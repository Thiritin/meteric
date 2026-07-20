<?php

declare(strict_types=1);

namespace Meteric\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Meteric\Models\Order;

/** A pending order was canceled before payment. */
final class OrderCanceled
{
    use Dispatchable;

    public function __construct(
        public readonly Order $order,
    ) {}
}
