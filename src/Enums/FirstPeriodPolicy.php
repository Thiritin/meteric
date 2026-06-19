<?php

declare(strict_types=1);

namespace Meteric\Enums;

enum FirstPeriodPolicy: string
{
    case ProrateOnly = 'prorate_only';            // bill the stub only
    case ProratePlusFull = 'prorate_plus_full';   // stub + first full period
    case FullPeriod = 'full_period';              // one full period now
    case FreeUntilAnchor = 'free_until_anchor';   // stub free, charge at anchor
}
