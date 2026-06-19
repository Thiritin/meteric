<?php

declare(strict_types=1);

namespace Meteric\Enums;

enum SubscriptionState: string
{
    case Incomplete = 'incomplete';
    case Trialing = 'trialing';
    case Active = 'active';
    case PastDue = 'past_due';
    case Paused = 'paused';
    case Canceled = 'canceled';
    case Expired = 'expired';

    public function isBillable(): bool
    {
        return in_array($this, [self::Active, self::Trialing, self::PastDue], true);
    }
}
