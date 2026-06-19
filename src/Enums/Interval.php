<?php

declare(strict_types=1);

namespace Meteric\Enums;

use Carbon\CarbonImmutable;

enum Interval: string
{
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';
    case Year = 'year';

    /** Add `count` of this interval to a moment (calendar-aware for month/year). */
    public function add(CarbonImmutable $from, int $count): CarbonImmutable
    {
        return match ($this) {
            self::Day => $from->addDays($count),
            self::Week => $from->addWeeks($count),
            self::Month => $from->addMonthsNoOverflow($count),
            self::Year => $from->addYearsNoOverflow($count),
        };
    }

    /** Subtract `count` of this interval (calendar-aware). */
    public function subtract(CarbonImmutable $from, int $count): CarbonImmutable
    {
        return match ($this) {
            self::Day => $from->subDays($count),
            self::Week => $from->subWeeks($count),
            self::Month => $from->subMonthsNoOverflow($count),
            self::Year => $from->subYearsNoOverflow($count),
        };
    }
}
