<?php

declare(strict_types=1);

namespace Meteric\Enums;

/**
 * How an upgrade to a higher-priced plan is applied.
 */
enum UpgradePolicy: string
{
    case ProrateNow = 'prorate_now';  // credit the unused old, charge the prorated new, for the rest of the cycle
    case Defer = 'defer';             // swap at the next renewal, keep the current plan until then
}
