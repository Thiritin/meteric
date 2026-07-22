<?php

declare(strict_types=1);

namespace Meteric\Proration;

use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Carbon\CarbonImmutable;
use Meteric\Support\Period;

/** Builds Proration value objects using the configured unit + rounding mode. */
final class Prorator
{
    public function __construct(
        private string $unit = 'second',
        private RoundingMode $rounding = RoundingMode::HALF_UP,
    ) {}

    public function for(Period $period, CarbonImmutable $changeAt, Money $fullAmount): Proration
    {
        return new Proration($period, $changeAt, $fullAmount, $this->unit, $this->rounding);
    }

    /** Net change when swapping amounts mid-period: credit old + charge new. */
    public function swap(Period $period, CarbonImmutable $changeAt, Money $oldFull, Money $newFull): Money
    {
        // Both legs share the same ratio, so prorate the net delta and round
        // once. Rounding the credit and the charge separately can leave the net
        // off by a minor unit.
        $ratio = $this->for($period, $changeAt, $newFull)->ratio();

        return $newFull->minus($oldFull)->multipliedBy($ratio, $this->rounding);
    }
}
