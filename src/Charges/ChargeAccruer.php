<?php

declare(strict_types=1);

namespace Meteric\Charges;

use Brick\Money\Money;
use Illuminate\Support\Facades\DB;
use Meteric\Anchoring\BillingPlan;
use Meteric\Anchoring\PlannedPeriod;
use Meteric\Enums\DiscountTarget;
use Meteric\Enums\ItemState;
use Meteric\Enums\LineKind;
use Meteric\Models\Addon;
use Meteric\Models\BillingPeriod;
use Meteric\Models\Charge;
use Meteric\Models\Discount;
use Meteric\Models\ItemOption;
use Meteric\Models\Price;
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
        $full = $item->periodAmount();

        return DB::transaction(function () use ($item, $plan, $price, $full): array {
            $created = [];

            foreach ($plan->charges as $pp) {
                if (! $this->reserve($item, $pp->period)) {
                    continue; // window already billed → skip (idempotent)
                }
                if ($pp->free) {
                    continue; // reserved so it won't re-bill, but nothing owed
                }

                $amount = $pp->prorated ? $this->prorate($pp, $price, $full) : $full;

                $period = [Charge::pendingForItem($item, [
                    'kind' => $pp->kind,
                    'description' => $pp->period->label(),    // the service period, on its own line
                    'quantity' => $item->quantity,
                    'unit' => $price->interval?->value,   // month, year, ...
                    'unit_minor' => $price->unit_rate === null ? $price->amount_minor : null,
                    'unit_rate' => $price->unit_rate,
                    'amount_minor' => $amount->getMinorAmount()->toInt(),
                    'covers' => $pp->period,
                    'idempotency_key' => $this->key($item, $pp),
                ])];

                // Configurable options and addons recur with the item: bill each
                // for the same period. Gated by the base reservation above, so a
                // re-run of an already-billed period skips these too.
                $period = array_merge($period, $this->billExtras($item, $pp->period));

                // A discount comes off what the period actually billed, so it
                // is raised last and reads the figures above it.
                $period = array_merge($period, $this->billDiscounts($item, $pp->period, $period));

                $created = array_merge($created, $period);
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
    private function billExtras(SubscriptionItem $item, Period $period): array
    {
        $created = [];

        foreach ($this->optionAmounts($item) as ['option' => $option, 'amount' => $amount]) {
            if ($amount->isZero()) {
                continue;
            }
            $price = $option->price;

            $created[] = Charge::pendingForItem($item, [
                'origin_type' => 'item_option',
                'origin_id' => $option->id,
                'kind' => LineKind::Option,
                'description' => ucfirst($option->key),
                'quantity' => $option->quantity,
                'unit' => $price->interval?->value,
                'unit_minor' => $price->unit_rate === null ? $price->amount_minor : null,
                'unit_rate' => $price->unit_rate,
                'amount_minor' => $amount->getMinorAmount()->toInt(),
                'covers' => $period,
                'idempotency_key' => 'opt_'.substr(hash('sha256', $option->id.$period->toRange()), 0, 36),
            ]);
        }

        foreach ($this->addonAmounts($item, $item->periodAmount()) as ['addon' => $addon, 'amount' => $amount, 'relative' => $relative]) {
            if ($amount->isZero()) {
                continue;
            }
            $price = $addon->price;
            $amountMinor = $amount->getMinorAmount()->toInt();

            $created[] = Charge::pendingForItem($item, [
                'origin_type' => 'addon',
                'origin_id' => $addon->id,
                'kind' => LineKind::Addon,
                'description' => $relative
                    ? $price->percentLabel().'% of '.($item->product->name ?? 'plan')
                    : ($addon->product->name ?? 'Addon'),
                'quantity' => $relative ? 1 : $addon->quantity,
                'unit' => $price->interval?->value,
                'unit_minor' => $relative ? $amountMinor : ($price->unit_rate === null ? $price->amount_minor : null),
                'unit_rate' => $relative ? null : $price->unit_rate,
                'amount_minor' => $amountMinor,
                'covers' => $period,
                'idempotency_key' => 'addon_'.substr(hash('sha256', $addon->id.$period->toRange()), 0, 34),
            ]);
        }

        return $created;
    }

    /**
     * Negative `discount` charges for the item's active discounts, one per
     * discount, in the item's own line group so the invoice nests them under
     * the thing they reduce and the period's tax falls with them.
     *
     * The base is what this period billed, and each discount applies to what is
     * left after the ones before it, so a stack can zero a period but never
     * invert it. A period that billed nothing raises none and spends no term.
     *
     * @param  list<Charge>  $billed  the charges this period just raised
     * @return list<Charge>
     */
    private function billDiscounts(SubscriptionItem $item, Period $period, array $billed): array
    {
        $remaining = 0;
        foreach ($billed as $charge) {
            $remaining += $charge->amount_minor;
        }

        if ($remaining <= 0) {
            return [];
        }

        $created = [];

        foreach ($this->reductions($item, $remaining) as ['discount' => $discount, 'off' => $off]) {
            $discount->consume();

            if ($off <= 0) {
                continue;
            }

            $created[] = Charge::pendingForItem($item, [
                'origin_type' => 'discount',
                'origin_id' => $discount->id,
                'kind' => LineKind::Discount,
                'description' => $discount->label,
                'quantity' => 1,
                'amount_minor' => -$off,
                'covers' => $period,
                'idempotency_key' => 'disc_'.substr(hash('sha256', $discount->id.$period->toRange()), 0, 35),
            ]);
        }

        return $created;
    }

    /**
     * What accruing one period at $price would bill this item: the period
     * itself, its options and addons, less its discounts. Writes nothing and
     * spends no discount term, so a preview can quote what accrue() will then
     * charge from the same arithmetic.
     */
    public function quote(SubscriptionItem $item, Price $price, Period $period): Money
    {
        $base = $price->amountFor((float) $item->quantity);
        $total = $base;

        foreach ($this->optionAmounts($item) as ['amount' => $amount]) {
            $total = $total->plus($amount);
        }

        foreach ($this->addonAmounts($item, $base) as ['amount' => $amount]) {
            $total = $total->plus($amount);
        }

        $minor = $total->getMinorAmount()->toInt();

        if ($minor <= 0) {
            return $total;
        }

        foreach ($this->reductions($item, $minor) as ['off' => $off]) {
            $total = $total->minus(Money::ofMinor(max(0, $off), $total->getCurrency()));
        }

        return $total;
    }

    /**
     * The item's configurable options priced for one period.
     *
     * @return list<array{option:ItemOption,amount:Money}>
     */
    private function optionAmounts(SubscriptionItem $item): array
    {
        $priced = [];

        foreach ($item->options as $option) {
            if ($option->price_id === null) {
                continue;
            }

            $priced[] = ['option' => $option, 'amount' => $option->price->amountForQuantity((float) $option->quantity)];
        }

        return $priced;
    }

    /**
     * The item's active addons priced for one period. A relative addon is a
     * percentage of $base, the period's own base amount.
     *
     * @return list<array{addon:Addon,amount:Money,relative:bool}>
     */
    private function addonAmounts(SubscriptionItem $item, Money $base): array
    {
        $priced = [];

        foreach ($item->addons()->where('state', ItemState::Active->value)->get() as $addon) {
            $relative = $addon->price->isRelative();

            $priced[] = [
                'addon' => $addon,
                'amount' => $relative ? $addon->price->amountOfBase($base) : $addon->price->amountForQuantity((float) $addon->quantity),
                'relative' => $relative,
            ];
        }

        return $priced;
    }

    /**
     * What the item's line discounts take off a period that billed $remaining,
     * in order, each applied to what is left after the ones before it. A
     * discount with no terms left is not offered and spends nothing.
     *
     * @return list<array{discount:Discount,off:int}>
     */
    private function reductions(SubscriptionItem $item, int $remaining): array
    {
        $currency = $item->subscription->currency;
        $offs = [];

        $discounts = Models::query(Discount::class)
            ->where('item_id', $item->id)
            ->active()
            ->forTarget(DiscountTarget::Line)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        foreach ($discounts as $discount) {
            if (! $discount->hasTermsLeft()) {
                continue;
            }

            $off = $discount->reduce(Money::ofMinor($remaining, $currency))->getMinorAmount()->toInt();
            $offs[] = ['discount' => $discount, 'off' => $off];

            if ($off > 0) {
                $remaining -= $off;
            }
        }

        return $offs;
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
