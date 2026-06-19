<?php

declare(strict_types=1);

namespace Meteric\Subscriptions;

use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Meteric\Contracts\Clock;
use Meteric\Enums\ChargeState;
use Meteric\Enums\CommitmentState;
use Meteric\Enums\Interval;
use Meteric\Enums\LineKind;
use Meteric\Models\Charge;
use Meteric\Models\Commitment;
use Meteric\Models\SubscriptionItem;
use Meteric\Support\Period;

/**
 * Term commitments / reservations (AWS RI / WHMCS contract term). A commitment
 * locks a term, an optional upfront payment, and a reduced recurring rate that
 * the accruer applies while the commitment is active. Early termination bills a
 * fee per the commitment's rule.
 */
final class CommitmentManager
{
    public function __construct(private Clock $clock) {}

    /**
     * @param  array{fee_minor?:int,remaining_pct?:float}  $earlyTerm
     */
    public function commit(
        SubscriptionItem $item,
        Interval $termInterval,
        int $termCount,
        Money $upfront,
        Money $rate,
        array $earlyTerm = [],
        ?CarbonImmutable $at = null,
    ): Commitment {
        $at ??= $this->clock->now();
        $term = new Period($at, $termInterval->add($at, $termCount));

        return DB::transaction(function () use ($item, $termInterval, $termCount, $upfront, $rate, $earlyTerm, $term): Commitment {
            $commitment = Commitment::create([
                'item_id' => $item->id,
                'term_interval' => $termInterval,
                'term_count' => $termCount,
                'upfront_minor' => $upfront->getMinorAmount()->toInt(),
                'rate_minor' => $rate->getMinorAmount()->toInt(),
                'currency' => $rate->getCurrency()->getCurrencyCode(),
                'term' => $term,
                'early_term' => $earlyTerm,
                'state' => CommitmentState::Active,
            ]);

            if ($upfront->isPositive()) {
                $this->charge($item, $upfront, LineKind::OneOff, 'Commitment upfront', $term);
            }

            return $commitment;
        });
    }

    /** Terminate early — bill the configured fee and close the commitment. */
    public function terminate(Commitment $commitment, ?CarbonImmutable $at = null): Money
    {
        $at ??= $this->clock->now();
        $fee = $this->earlyTerminationFee($commitment, $at);

        return DB::transaction(function () use ($commitment, $fee): Money {
            if ($fee->isPositive()) {
                $this->charge($commitment->item, $fee, LineKind::OneOff, 'Early termination fee', $commitment->term);
            }
            $commitment->forceFill(['state' => CommitmentState::Terminated])->save();

            return $fee;
        });
    }

    private function earlyTerminationFee(Commitment $commitment, CarbonImmutable $at): Money
    {
        $rule = $commitment->early_term ?? [];
        $currency = $commitment->currency;

        if (isset($rule['fee_minor'])) {
            return Money::ofMinor((int) $rule['fee_minor'], $currency);
        }

        // remaining_pct of the remaining committed value (whole periods left × rate).
        $pct = (float) ($rule['remaining_pct'] ?? 0);
        if ($pct <= 0 || $commitment->term === null) {
            return Money::ofMinor(0, $currency);
        }

        $remainingSeconds = max(0, $commitment->term->remainingSecondsFrom($at));
        $periodSeconds = max(1, (int) ($commitment->term->totalSeconds() / $commitment->term_count));
        $periodsLeft = (int) ceil($remainingSeconds / $periodSeconds);

        return $commitment->committedRate()
            ->multipliedBy($periodsLeft, RoundingMode::HALF_UP)
            ->multipliedBy($pct, RoundingMode::HALF_UP);
    }

    private function charge(SubscriptionItem $item, Money $amount, LineKind $kind, string $desc, ?Period $covers): void
    {
        $sub = $item->subscription;

        Charge::create([
            'account_id' => $sub->account_id,
            'subscription_id' => $sub->id,
            'origin_type' => 'commitment',
            'origin_id' => $item->id,
            'kind' => $kind,
            'billing_mode' => $item->billingMode(),
            'state' => ChargeState::Pending,
            'description' => $desc,
            'quantity' => 1,
            'unit_minor' => $amount->getMinorAmount()->toInt(),
            'amount_minor' => $amount->getMinorAmount()->toInt(),
            'currency' => $sub->currency,
            'covers' => $covers,
            'idempotency_key' => 'commit_'.Str::uuid()->toString(),
        ]);
    }
}
