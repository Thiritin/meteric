<?php

declare(strict_types=1);

namespace Meteric\Charges;

use Brick\Money\Money;
use Illuminate\Support\Facades\DB;
use Meteric\Anchoring\BillingPlan;
use Meteric\Anchoring\PlannedPeriod;
use Meteric\Enums\ChargeState;
use Meteric\Enums\ItemState;
use Meteric\Enums\LineKind;
use Meteric\Models\BillingPeriod;
use Meteric\Models\Charge;
use Meteric\Models\Price;
use Meteric\Models\Subscription;
use Meteric\Models\SubscriptionItem;
use Meteric\Proration\Prorator;
use Meteric\Support\Models;
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
        $full = $item->periodAmount();

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

                $created[] = Models::query(Charge::class)->create([
                    'account_id' => $sub->account_id,
                    'subscription_id' => $sub->id,
                    'origin_type' => 'subscription_item',
                    'origin_id' => $item->id,
                    'kind' => $pp->kind,
                    'billing_mode' => $item->billingMode(),
                    'state' => ChargeState::Pending,
                    'title' => $item->lineTitle(),
                    'group' => $item->group,
                    'line_group' => $item->id,
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

                // Configurable options and addons recur with the item: bill each
                // for the same period. Gated by the base reservation above, so a
                // re-run of an already-billed period skips these too.
                $created = array_merge($created, $this->billExtras($item, $sub, $pp->period));
            }

            $item->forceFill(['current_period' => $plan->ongoing])->save();

            return $created;
        });
    }

    /**
     * Recurring charges for an item's active configurable options and addons,
     * priced through the same Price engine (tiers included) for one period.
     *
     * @return list<Charge>
     */
    private function billExtras(SubscriptionItem $item, Subscription $sub, Period $period): array
    {
        $created = [];

        foreach ($item->options as $option) {
            if ($option->price_id === null) {
                continue;
            }
            $price = $option->price;
            $amount = $price->amountForQuantity((float) $option->quantity);
            if ($amount->isZero()) {
                continue;
            }

            $created[] = Models::query(Charge::class)->create([
                'account_id' => $sub->account_id,
                'subscription_id' => $sub->id,
                'origin_type' => 'item_option',
                'origin_id' => $option->id,
                'kind' => LineKind::Option,
                'billing_mode' => $item->billingMode(),
                'state' => ChargeState::Pending,
                'title' => $item->lineTitle(),
                'group' => $item->group,
                'line_group' => $item->id,
                'description' => ucfirst($option->key),
                'quantity' => $option->quantity,
                'unit' => $price->interval?->value,
                'unit_minor' => $price->unit_rate === null ? $price->amount_minor : null,
                'unit_rate' => $price->unit_rate,
                'amount_minor' => $amount->getMinorAmount()->toInt(),
                'currency' => $sub->currency,
                'covers' => $period,
                'idempotency_key' => 'opt_'.substr(hash('sha256', $option->id.$period->toRange()), 0, 36),
            ]);
        }

        foreach ($item->addons()->where('state', ItemState::Active->value)->get() as $addon) {
            $price = $addon->price;
            $relative = $price->isRelative();
            $amount = $relative ? $price->amountOfBase($item->periodAmount()) : $price->amountForQuantity((float) $addon->quantity);
            if ($amount->isZero()) {
                continue;
            }
            $amountMinor = $amount->getMinorAmount()->toInt();

            $created[] = Models::query(Charge::class)->create([
                'account_id' => $sub->account_id,
                'subscription_id' => $sub->id,
                'origin_type' => 'addon',
                'origin_id' => $addon->id,
                'kind' => LineKind::Addon,
                'billing_mode' => $item->billingMode(),
                'state' => ChargeState::Pending,
                'title' => $item->lineTitle(),
                'group' => $item->group,
                'line_group' => $item->id,
                'description' => $relative
                    ? $price->percentLabel().'% of '.($item->product->name ?? 'plan')
                    : ($addon->product->name ?? 'Addon'),
                'quantity' => $relative ? 1 : $addon->quantity,
                'unit' => $price->interval?->value,
                'unit_minor' => $relative ? $amountMinor : ($price->unit_rate === null ? $price->amount_minor : null),
                'unit_rate' => $relative ? null : $price->unit_rate,
                'amount_minor' => $amountMinor,
                'currency' => $sub->currency,
                'covers' => $period,
                'idempotency_key' => 'addon_'.substr(hash('sha256', $addon->id.$period->toRange()), 0, 34),
            ]);
        }

        return $created;
    }

    /**
     * Reserve a window in the guard table. Returns false if it overlaps one
     * already billed. A pre-check keeps the surrounding transaction alive (a
     * raw EXCLUDE violation would poison it); the DB constraint remains the hard
     * backstop against concurrent races.
     */
    private function reserve(SubscriptionItem $item, Period $period): bool
    {
        $overlaps = Models::query(BillingPeriod::class)
            ->where('item_id', $item->id)
            ->whereNull('dimension_id')
            ->whereRaw('covers && ?::tstzrange', [$period->toRange()])
            ->exists();

        if ($overlaps) {
            return false;
        }

        Models::query(BillingPeriod::class)->create(['item_id' => $item->id, 'covers' => $period]);

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
