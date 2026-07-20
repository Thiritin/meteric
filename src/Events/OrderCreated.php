<?php

declare(strict_types=1);

namespace Meteric\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Meteric\Models\Order;

/** A pending order was opened. The cart is frozen; nothing is billed yet. */
final class OrderCreated
{
    use Dispatchable;

    public function __construct(
        public readonly Order $order,
    ) {}
}
