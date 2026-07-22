<?php

declare(strict_types=1);

namespace Meteric\Pricing;

use Brick\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Meteric\Anchoring\PeriodPlanner;
use Meteric\Anchoring\PlannedPeriod;
use Meteric\Contracts\TaxResolver;
use Meteric\Enums\AnchorMode;
use Meteric\Enums\FirstPeriodPolicy;
use Meteric\Enums\LineKind;
use Meteric\Models\Price;
use Meteric\Proration\Prorator;
use Meteric\Quoting\Quote;
use Meteric\Quoting\QuoteLine;
use Meteric\Support\Period;
use Meteric\Tax\TaxContext;

/**
 * Prices a checkout cart and freezes it: it runs the same planner / prorator /
 * tax stack that real billing uses, then returns the array written verbatim to
 * the Order's `contents` jsonb. Because the amounts are frozen at this moment,
 * the eventual invoice matches the order even if the catalog price later moves.
 *
 * The returned shape per cart row:
 *  product_id, price_id, quantity, label, group, resource_type, resource_id,
 *  amount_minor (frozen due-now base amount), kind (frozen LineKind),
 *  covers [iso,iso]|null, addons[], options[].
 */
final class CheckoutPricer
{
    public function __construct(
        private PeriodPlanner $planner,
        private Prorator $prorator,
        private TaxResolver $tax,
    ) {}

    /**
     * @param  list<array{price:Price,qty:float,resource:?Model,label:?string,group:?string,addons:list<array{price:Price,group:?string,qty:float}>,options:list<array{key:string,value:string,type:string,price:?Price,qty:float,min:?float,max:?float,label:?string}>}>  $cart
     */
    public function price(
        array $cart,
        string $currency,
        CarbonImmutable $at,
        AnchorMode $anchorMode,
        ?int $anchorDay,
        FirstPeriodPolicy $firstPeriod,
        int $trialDays,
        TaxContext $taxContext,
    ): FrozenCart {
        $terms = new CheckoutTerms($currency, $at, $anchorMode, $anchorDay, $firstPeriod, $trialDays, $taxContext);

        $rows = array_map(fn (array $row): PricedRow => $this->priceRow($row, $terms), $cart);

        return $this->freeze($rows, $terms);
    }

    /**
     * Price one cart row: the frozen base amount plus its addons and options,
     * the display line with tax, and the deltas the cart totals fold up.
     *
     * @param  array{price:Price,qty:float,resource:?Model,label:?string,group:?string,addons:list<array{price:Price,group:?string,qty:float}>,options:list<array{key:string,value:string,type:string,price:?Price,qty:float,min:?float,max:?float,label:?string}>}  $row
     */
    private function priceRow(array $row, CheckoutTerms $terms): PricedRow
    {
        $zero = $terms->zero();
        $price = $row['price'];
        $qty = $row['qty'];

        [$dueNow, $kind, $covers, $ongoing, $usage] = $this->priceBase($price, $qty, $terms);

        $addons = array_map(
            fn (array $addon): array => $this->priceAddon($addon, $price->amountFor($qty), $terms),
            $row['addons'],
        );
        $options = array_map(
            fn (array $option): array => $this->priceOption($option, $terms),
            $row['options'],
        );

        // Frozen due-now for the whole row: base + addons + options + setups.
        $rowDueNow = $dueNow;
        foreach ($addons as $a) {
            $rowDueNow = $rowDueNow->plus(Money::ofMinor($a['amount_minor'], $terms->currency));
        }
        foreach ($options as $o) {
            $rowDueNow = $rowDueNow->plus(Money::ofMinor($o['amount_minor'] + $o['setup_minor'], $terms->currency));
        }

        // Ongoing per-period total (display only): full base + full extras.
        $recurring = $zero;
        if ($price->isRecurring()) {
            $recurring = $price->amountFor($qty);
            foreach ($addons as $a) {
                $recurring = $recurring->plus(Money::ofMinor($a['amount_minor'], $terms->currency));
            }
            foreach ($options as $o) {
                $recurring = $recurring->plus(Money::ofMinor($o['amount_minor'], $terms->currency));
            }
        }

        $label = $row['label'] ?? $price->product->name ?? 'Item';

        return new PricedRow(
            content: [
                'product_id' => $price->product_id,
                'price_id' => $price->id,
                'quantity' => $qty,
                'label' => $row['label'] ?? null,
                'group' => $row['group'] ?? null,
                'resource_type' => $row['resource']?->getMorphClass(),
                'resource_id' => $row['resource'] !== null ? (string) $row['resource']->getKey() : null,
                'amount_minor' => $dueNow->getMinorAmount()->toInt(),
                'kind' => $kind->value,
                'covers' => $covers?->toArray(),
                'addons' => $addons,
                'options' => $options,
            ],
            line: new QuoteLine($label, $kind, $qty, $rowDueNow, $this->tax->resolve($rowDueNow, $terms->taxContext)->amount, covers: $covers),
            dueNow: $rowDueNow,
            recurring: $recurring,
            interval: $price->isRecurring() ? $price->interval?->value : null,
            intervalCount: $price->isRecurring() ? $price->interval_count : null,
            nextChargeAt: $price->isRecurring() ? $ongoing?->end : null,
            estimated: $usage,
        );
    }

    /**
     * Fold the priced rows into the quote and the frozen cart totals.
     *
     * @param  list<PricedRow>  $rows
     */
    private function freeze(array $rows, CheckoutTerms $terms): FrozenCart
    {
        $zero = $terms->zero();

        $subtotal = array_reduce($rows, fn (Money $c, PricedRow $r) => $c->plus($r->dueNow), $zero);
        $recurring = array_reduce($rows, fn (Money $c, PricedRow $r) => $c->plus($r->recurring), $zero);
        $lines = array_map(fn (PricedRow $r): QuoteLine => $r->line, $rows);
        $taxTotal = array_reduce($lines, fn (Money $c, QuoteLine $l) => $c->plus($l->tax), $zero);

        $interval = null;
        $intervalCount = null;
        $nextChargeAt = null;
        $estimated = false;
        foreach ($rows as $row) {
            $interval ??= $row->interval;
            $intervalCount ??= $row->intervalCount;
            $estimated = $estimated || $row->estimated;
            if ($row->nextChargeAt !== null) {
                $nextChargeAt = $nextChargeAt === null ? $row->nextChargeAt : min($nextChargeAt, $row->nextChargeAt);
            }
        }

        $quote = new Quote(
            currency: $terms->currency,
            dueNowSubtotal: $subtotal,
            dueNowTax: $taxTotal,
            dueNowTotal: $subtotal->plus($taxTotal),
            recurringTotal: $recurring,
            interval: $interval,
            intervalCount: $intervalCount,
            nextChargeAt: $nextChargeAt,
            lines: $lines,
            estimated: $estimated,
        );

        return new FrozenCart(
            contents: array_map(fn (PricedRow $r): array => $r->content, $rows),
            subtotalMinor: $subtotal->getMinorAmount()->toInt(),
            taxMinor: $taxTotal->getMinorAmount()->toInt(),
            totalMinor: $subtotal->plus($taxTotal)->getMinorAmount()->toInt(),
            recurringTotalMinor: $recurring->getMinorAmount()->toInt(),
            quoteSnapshot: $quote->toArray(),
        );
    }

    /**
     * Frozen due-now for a base line. Mirrors SubscriptionBuilder::addItem:
     *  - usage: bills in arrears, nothing due now (estimated quote).
     *  - one-off: a single immediate charge.
     *  - recurring (trial): nothing now, first renewal bills it.
     *  - recurring: the planner's first-period charge(s), prorated as billed.
     *
     * @return array{0:Money,1:LineKind,2:?Period,3:?Period,4:bool} due-now, kind, covers, ongoing, isUsage
     */
    private function priceBase(Price $price, float $qty, CheckoutTerms $terms): array
    {
        $zero = $terms->zero();
        $full = $price->amountFor($qty);

        if ($price->pricing_model->isUsageBased()) {
            return [$zero, LineKind::Usage, null, null, true];
        }

        if (! $price->isRecurring()) {
            return [$full, LineKind::OneOff, null, null, false];
        }

        $plan = $this->planner->plan($terms->at, $price->recurrence(), $terms->anchorMode, $terms->anchorDay, $terms->firstPeriod);

        if ($terms->trialing()) {
            // Trial: reserve nothing now; the ongoing period drives the first renewal.
            return [$zero, LineKind::Recurring, null, $plan->ongoing, false];
        }

        $dueNow = $zero;
        $kind = LineKind::Recurring;
        $covers = null;
        foreach ($plan->charges as $pp) {
            $amount = $pp->free ? $zero : ($pp->prorated ? $this->prorate($pp, $price, $full) : $full);
            $dueNow = $dueNow->plus($amount);
            $kind = $pp->kind;
            $covers = $pp->period;
        }

        return [$dueNow, $kind, $covers, $plan->ongoing, false];
    }

    /**
     * Frozen addon line. At signup the addon's first period equals the base's
     * ongoing cycle starting at `at`, so the prorated amount is the full first
     * period — exactly what the accruer bills on convert. Relative addons freeze
     * a percentage of the base's full period amount.
     *
     * @param  array{price:Price,group:?string,qty:float}  $addon
     * @return array{product_id:string,price_id:string,group_key:?string,quantity:float,amount_minor:int}
     */
    private function priceAddon(array $addon, Money $base, CheckoutTerms $terms): array
    {
        $price = $addon['price'];
        $qty = $addon['qty'];
        $full = $price->isRelative() ? $price->amountOfBase($base) : $price->amountForQuantity($qty);
        $dueNow = $terms->trialing() ? $terms->zero() : $full;

        return [
            'product_id' => $price->product_id,
            'price_id' => $price->id,
            'group_key' => $addon['group'] ?? null,
            'quantity' => $qty,
            'amount_minor' => $dueNow->getMinorAmount()->toInt(),
        ];
    }

    /**
     * Frozen option line: the recurring amount for the first period plus a
     * one-time setup fee (charged once, on convert).
     *
     * @param  array{key:string,value:string,type:string,price:?Price,qty:float,min:?float,max:?float,label:?string}  $option
     * @return array{key:string,value:string,label:?string,type:string,price_id:?string,quantity:float,min_qty:?float,max_qty:?float,amount_minor:int,setup_minor:int}
     */
    private function priceOption(array $option, CheckoutTerms $terms): array
    {
        $zero = $terms->zero();
        $price = $option['price'] ?? null;
        $qty = $option['qty'];

        $recurring = $price !== null ? $price->amountForQuantity($qty) : $zero;
        $dueNow = $terms->trialing() ? $zero : $recurring;
        $setup = $price !== null && $price->hasSetupFee() ? $price->setupFee() : $zero;

        return [
            'key' => $option['key'],
            'value' => $option['value'],
            'label' => $option['label'] ?? null,
            'type' => $option['type'],
            'price_id' => $price?->id,
            'quantity' => $qty,
            'min_qty' => $option['min'] ?? null,
            'max_qty' => $option['max'] ?? null,
            'amount_minor' => $dueNow->getMinorAmount()->toInt(),
            'setup_minor' => $setup->getMinorAmount()->toInt(),
        ];
    }

    private function prorate(PlannedPeriod $pp, Price $price, Money $full): Money
    {
        $cycle = new Period($price->recurrence()->previousStart($pp->period->end), $pp->period->end);

        return $this->prorator->for($cycle, $pp->period->start, $full)->amount();
    }
}
