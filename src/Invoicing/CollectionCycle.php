<?php

declare(strict_types=1);

namespace Meteric\Invoicing;

use Carbon\CarbonImmutable;

/**
 * The monthly boundary a collective account's invoice is issued on.
 *
 * The day is a number from 1 to 31 and short months clamp to their last day, so
 * a cycle set to the 31st issues on the 28th of February rather than skipping
 * it. A boundary is a date at midnight: everything accrued before it belongs to
 * the invoice it raises, and the stamp the run writes is that same date, which
 * is what makes a second run in the same cycle bill nothing.
 */
final class CollectionCycle
{
    /** The most recent boundary on or before $at. */
    public static function boundary(CarbonImmutable $at, int $day): CarbonImmutable
    {
        $current = self::inMonth($at, $day);

        if ($at->greaterThanOrEqualTo($current)) {
            return $current;
        }

        return self::inMonth($at->startOfMonth()->subMonthNoOverflow(), $day);
    }

    /** The first boundary after $at. */
    public static function next(CarbonImmutable $at, int $day): CarbonImmutable
    {
        $current = self::inMonth($at, $day);

        if ($at->lessThan($current)) {
            return $current;
        }

        return self::inMonth($at->startOfMonth()->addMonthNoOverflow(), $day);
    }

    private static function inMonth(CarbonImmutable $at, int $day): CarbonImmutable
    {
        $month = $at->startOfMonth();

        return $month->addDays(min($day, $month->daysInMonth) - 1);
    }
}
