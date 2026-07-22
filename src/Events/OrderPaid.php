<?php

declare(strict_types=1);

namespace Meteric\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Meteric\Models\Invoice;
use Meteric\Models\Order;
use Meteric\Models\Payment;

/** An order was paid in full and converted into a real subscription + invoice. */
final class OrderPaid
{
    use Dispatchable;

    public function __construct(
        public readonly Order $order,
        public readonly ?Invoice $invoice,
        public readonly ?Payment $payment,
    ) {}
}
