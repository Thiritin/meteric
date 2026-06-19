<?php

declare(strict_types=1);

namespace Meteric\Proration;

use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Carbon\CarbonImmutable;
use Meteric\Support\Period;

/**
 * Auditable proration value object: the period, the change instant, the full
 * amount, and the resulting prorated (signed) amount.
 */
final class Proration
{
    public function __construct(
        public readonly Period $period,
        public readonly CarbonImmutable $changeAt,
        public readonly Money $fullAmount,
        public readonly string $unit = 'second',
        private readonly RoundingMode $rounding = RoundingMode::HALF_UP,
    ) {}

    /** Fraction of the period remaining at the change instant, in [0,1]. */
    public function ratio(): float
    {
        $totalSeconds = $this->period->totalSeconds();
        $remainingSeconds = $this->period->remainingSecondsFrom($this->changeAt);

        if ($this->unit === 'day') {
            $total = max(1.0, round($totalSeconds / 86400));
            $remaining = round($remainingSeconds / 86400);

            return $this->clamp($remaining / $total);
        }

        return $this->clamp($remainingSeconds / max(1, $totalSeconds));
    }

    /** Prorated amount for the remaining portion (positive = charge). */
    public function amount(): Money
    {
        return $this->fullAmount->multipliedBy($this->ratio(), $this->rounding);
    }

    /** Credit for the unused portion if the item is removed at the change instant. */
    public function creditAmount(): Money
    {
        return $this->amount()->negated();
    }

    private function clamp(float $r): float
    {
        return max(0.0, min(1.0, $r));
    }
}
