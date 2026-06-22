<?php

declare(strict_types=1);

namespace Meteric\Enums;

/**
 * What happens to the unused value when a plan is downgraded.
 */
enum DowngradePolicy: string
{
    case Defer = 'defer';      // keep the current tier until the paid period ends, then renew lower (contracts)
    case Discard = 'discard';  // switch to the lower plan immediately; unused value is forfeited (prepaid)
    case Credit = 'credit';    // switch immediately and credit the unused old value as a pending charge on the next invoice
}
