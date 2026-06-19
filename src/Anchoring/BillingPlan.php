<?php

declare(strict_types=1);

namespace Meteric\Anchoring;

use Meteric\Support\Period;

/**
 * Result of planning the first cycle: the period(s) to bill now and the
 * "ongoing" period whose end triggers the next renewal.
 */
final class BillingPlan
{
    /** @param list<PlannedPeriod> $charges */
    public function __construct(
        public readonly array $charges,
        public readonly Period $ongoing,
    ) {}

    /** @return array<int,array<string,mixed>> */
    public function chargesToArray(): array
    {
        return array_map(static fn (PlannedPeriod $p) => $p->toArray(), $this->charges);
    }
}
