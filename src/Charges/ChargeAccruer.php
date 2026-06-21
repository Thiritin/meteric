<?php

declare(strict_types=1);

namespace Meteric\Charges;

use Brick\Money\Money;
use Illuminate\Support\Facades\DB;
use Meteric\Anchoring\BillingPlan;
use Meteric\Anchoring\PlannedPeriod;
use Meteric\Enums\ChargeState;
use Meteric\Models\BillingPeriod;
use Meteric\Models\Charge;
use Meteric\Models\Price;
use Meteric\Models\SubscriptionItem;
use Meteric\Proration\Prorator;
use Meteric\Support\Period;

/**
 * Turns a BillingPlan into pending Charge rows for a subscription item, guarded
 * by meteric_billing_periods so a window is never billed twice. Sets the item's
 * current_period to the plan's ongoing period (drives the next renewal).
 */
final class ChargeAccruer
{
    public function __construct(private Prorator $prorator) {}

    /** @return list<Charge> the charges created (free periods produce none) */
    public function accrue(SubscriptionItem $item, BillingPlan $plan): array
    {
        $price = $item->price;
        $sub = $item->subscription;
        $full = $item->periodAmount(); // committed rate if under an active commitment

        return DB::transaction(function () use ($item, $plan, $price, $sub, $full): array {
            $created = [];

            foreach ($plan->charges as $pp) {
                if (! $this->reserve($item, $pp->period)) {
                    continue; // window already billed → skip (idempotent)
                }
                if ($pp->free) {
                    continue; // reserved so it won't re-bill, but nothing owed
                }

                $amount = $pp->prorated ? $this->prorate($pp, $price, $full) : $full;

                $created[] = Charge::create([
                    'account_id' => $sub->account_id,
                    'subscription_id' => $sub->id,
                    'origin_type' => 'subscription_item',
                    'origin_id' => $item->id,
                    'kind' => $pp->kind,
                    'billing_mode' => $item->billingMode(),
                    'state' => ChargeState::Pending,
                    'title' => $item->lineTitle(),
                    'group' => $item->group,
                    'description' => $pp->period->label(),    // the service period, on its own line
                    'quantity' => $item->quantity,
                    'unit' => $price->interval?->value,   // month, year, ...
                    'unit_minor' => $price->unit_rate === null ? $price->amount_minor : null,
                    'unit_rate' => $price->unit_rate,
                    'amount_minor' => $amount->getMinorAmount()->toInt(),
                    'currency' => $sub->currency,
                    'covers' => $pp->period,
                    'idempotency_key' => $this->key($item, $pp),
                ]);
            }

            $item->forceFill(['current_period' => $plan->ongoing])->save();

            return $created;
        });
    }

    /**
     * Reserve a window in the guard table. Returns false if it overlaps one
     * already billed. A pre-check keeps the surrounding transaction alive (a
     * raw EXCLUDE violation would poison it); the DB constraint remains the hard
     * backstop against concurrent races.
     */
    private function reserve(SubscriptionItem $item, Period $period): bool
    {
        $overlaps = BillingPeriod::query()
            ->where('item_id', $item->id)
            ->whereNull('dimension_id')
            ->whereRaw('covers && ?::tstzrange', [$period->toRange()])
            ->exists();

        if ($overlaps) {
            return false;
        }

        BillingPeriod::create(['item_id' => $item->id, 'covers' => $period]);

        return true;
    }

    private function prorate(PlannedPeriod $pp, Price $price, Money $full): Money
    {
        $cycle = new Period($price->recurrence()->previousStart($pp->period->end), $pp->period->end);

        return $this->prorator->for($cycle, $pp->period->start, $full)->amount();
    }

    private function key(SubscriptionItem $item, PlannedPeriod $pp): string
    {
        return 'acc_'.substr(hash('sha256', $item->id.$pp->kind->value.$pp->period->toRange()), 0, 40);
    }
}
