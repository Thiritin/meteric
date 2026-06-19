<?php

declare(strict_types=1);

namespace Meteric\Enums;

/**
 * What happens to the unused value when a prepaid plan is downgraded.
 * Neither option refunds, credits, or extends — they only differ on timing.
 */
enum DowngradePolicy: string
{
    case Defer = 'defer';      // keep current tier until the paid period ends, then renew lower (contracts)
    case Discard = 'discard';  // switch to the lower plan now; unused value is forfeited (prepaid)
}
