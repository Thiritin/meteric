<?php

declare(strict_types=1);

namespace Meteric\Quoting;

use Brick\Money\Money;
use Carbon\CarbonImmutable;
use Meteric\Anchoring\PeriodPlanner;
use Meteric\Anchoring\PlannedPeriod;
use Meteric\Contracts\Clock;
use Meteric\Contracts\TaxResolver;
use Meteric\Enums\AnchorMode;
use Meteric\Enums\FirstPeriodPolicy;
use Meteric\Enums\LineKind;
use Meteric\Models\Price;
use Meteric\Proration\Prorator;
use Meteric\Support\Period;
use Meteric\Tax\TaxContext;

/**
 * Builds a read-only Quote — no persistence. Same planner/prorator/tax stack as
 * real billing, so the quote matches the invoice that will later be issued.
 */
final class QuoteBuilder
{
    /** @var list<array{price:Price,qty:float,label:string}> */
    private array $items = [];

    private AnchorMode $anchorMode = AnchorMode::Signup;

    private ?int $anchorDay = null;

    private FirstPeriodPolicy $firstPeriod = FirstPeriodPolicy::ProrateOnly;

    private ?CarbonImmutable $at = null;

    private TaxContext $taxContext;

    public function __construct(
        private Clock $clock,
        private Prorator $prorator,
        private TaxResolver $tax,
        private PeriodPlanner $planner,
        private string $currency = 'EUR',
    ) {
        $this->taxContext = new TaxContext;
    }

    public function currency(string $currency): self
    {
        $this->currency = $currency;

        return $this;
    }

    public function anchor(AnchorMode $mode, ?int $day = null): self
    {
        $this->anchorMode = $mode;
        $this->anchorDay = $day;

        return $this;
    }

    public function firstPeriod(FirstPeriodPolicy $policy): self
    {
        $this->firstPeriod = $policy;

        return $this;
    }

    public function at(CarbonImmutable $at): self
    {
        $this->at = $at;

        return $this;
    }

    public function tax(TaxContext $context): self
    {
        $this->taxContext = $context;

        return $this;
    }

    public function add(Price $price, float $qty = 1, ?string $label = null): self
    {
        $this->items[] = ['price' => $price, 'qty' => $qty, 'label' => $label ?? $price->product->name ?? 'Item'];

        return $this;
    }

    public function build(): Quote
    {
        $at = $this->at ?? $this->clock->now();
        $zero = Money::ofMinor(0, $this->currency);

        $lines = [];
        $recurringTotal = $zero;
        $estimated = false;
        $interval = null;
        $intervalCount = null;
        $nextChargeAt = null;

        foreach ($this->items as $item) {
            /** @var Price $price */
            $price = $item['price'];
            $qty = $item['qty'];
            $label = $item['label'];
            $full = $price->amountFor($qty);

            if ($price->pricing_model->isUsageBased()) {
                // Usage bills in arrears; show the rate, amount unknown yet.
                $lines[] = new QuoteLine($label, LineKind::Usage, $qty, $zero, $zero,
                    unitRate: $price->unit_rate, estimated: true);
                $estimated = true;

                continue;
            }

            if (! $price->isRecurring()) {
                $tax = $this->tax->resolve($full, $this->taxContext);
                $lines[] = new QuoteLine($label, LineKind::OneOff, $qty, $full, $tax->amount);

                continue;
            }

            $plan = $this->planner->plan($at, $price->recurrence(), $this->anchorMode, $this->anchorDay, $this->firstPeriod);

            foreach ($plan->charges as $pp) {
                $amount = $pp->free ? $zero : ($pp->prorated ? $this->prorate($pp, $price, $full) : $full);
                $tax = $this->tax->resolve($amount, $this->taxContext);
                $lines[] = new QuoteLine($label, $pp->kind, $qty, $amount, $tax->amount, covers: $pp->period);
            }

            $recurringTotal = $recurringTotal->plus($full);
            $interval ??= $price->interval?->value;
            $intervalCount ??= $price->interval_count;
            $end = $plan->ongoing->end;
            $nextChargeAt = $nextChargeAt === null ? $end : min($nextChargeAt, $end);
        }

        $subtotal = array_reduce($lines, fn (Money $c, QuoteLine $l) => $c->plus($l->amount), $zero);
        $taxTotal = array_reduce($lines, fn (Money $c, QuoteLine $l) => $c->plus($l->tax), $zero);

        return new Quote(
            currency: $this->currency,
            dueNowSubtotal: $subtotal,
            dueNowTax: $taxTotal,
            dueNowTotal: $subtotal->plus($taxTotal),
            recurringTotal: $recurringTotal,
            interval: $interval,
            intervalCount: $intervalCount,
            nextChargeAt: $nextChargeAt,
            lines: $lines,
            estimated: $estimated,
        );
    }

    /** Prorate a stub against the cycle it belongs to (ratio = stub ÷ full cycle). */
    private function prorate(PlannedPeriod $pp, Price $price, Money $full): Money
    {
        $cycle = new Period($price->recurrence()->previousStart($pp->period->end), $pp->period->end);

        return $this->prorator->for($cycle, $pp->period->start, $full)->amount();
    }
}
