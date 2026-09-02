<?php

declare(strict_types=1);

namespace Meteric\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Meteric\Models\Order;
use Meteric\Models\Subscription;

/**
 * A pending order reached `converted`: through payment or confirmation, which
 * materialize a subscription, or through completeOrder(), which does not.
 */
final class OrderConverted
{
    use Dispatchable;

    public function __construct(
        public readonly Order $order,
        public readonly ?Subscription $subscription,
    ) {}
}
