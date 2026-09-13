<?php

declare(strict_types=1);

namespace Meteric\Enums;

/**
 * How an upgrade to a higher-priced plan is applied.
 */
enum UpgradePolicy: string
{
    case Prorate = 'prorate';  // credit the unused old, charge the prorated new, for the rest of the cycle
    case Defer = 'defer';             // swap at the next renewal, keep the current plan until then
    case Discard = 'discard';  // swap now and charge nothing for the rest of the cycle; the next renewal bills the new plan
}
