<?php

declare(strict_types=1);

namespace Meteric\Enums;

/** Lifecycle of a checkout (an Order): pending until paid, then terminal. */
enum OrderState: string
{
    case Pending = 'pending';
    case Converted = 'converted';
    case Expired = 'expired';
    case Canceled = 'canceled';

    /** Only a pending order can still convert into a subscription + invoice. */
    public function canConvert(): bool
    {
        return $this === self::Pending;
    }

    /** A terminal order is settled (converted/expired/canceled) and immutable. */
    public function isTerminal(): bool
    {
        return $this !== self::Pending;
    }
}
