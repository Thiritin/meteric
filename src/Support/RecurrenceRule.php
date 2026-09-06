<?php

declare(strict_types=1);

namespace Meteric\Support;

use Carbon\CarbonImmutable;
use Meteric\Enums\Interval;

/**
 * Stripe-style dynamic recurrence: every {count} {interval}.
 * count = null means one-off (no recurrence).
 */
final class RecurrenceRule
{
    public function __construct(
        public readonly ?Interval $interval,
        public readonly ?int $count = 1,
    ) {}

    public static function oneOff(): self
    {
        return new self(null, null);
    }

    public function isRecurring(): bool
    {
        return $this->interval !== null && $this->count !== null;
    }

    /** Period end produced by applying the rule once from $start. */
    public function nextEnd(CarbonImmutable $start): CarbonImmutable
    {
        if (! $this->isRecurring()) {
            return $start;
        }

        return $this->interval->add($start, $this->count);
    }

    public function period(CarbonImmutable $start): Period
    {
        return new Period($start, $this->nextEnd($start));
    }

    /** Whether two rules bill on the same cycle, so an amount from one is comparable with an amount from the other. */
    public function equals(self $other): bool
    {
        return $this->interval === $other->interval
            && ($this->count ?? 1) === ($other->count ?? 1);
    }

    /** Start of the cycle that ends at $end — used as the proration denominator. */
    public function previousStart(CarbonImmutable $end): CarbonImmutable
    {
        if (! $this->isRecurring()) {
            return $end;
        }

        return $this->interval->subtract($end, $this->count);
    }
}
