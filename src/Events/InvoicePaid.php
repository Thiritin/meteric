<?php

declare(strict_types=1);

namespace Meteric\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Meteric\Models\Invoice;
use Meteric\Models\Payment;

/**
 * An invoice was paid in full. Listen here to auto-resume services that were
 * suspended for non-payment: iterate $invoice->billedSubscriptions() and resume the
 * paused ones, then start the resource in your provisioner.
 */
final class InvoicePaid
{
    use Dispatchable;

    public function __construct(
        public readonly Invoice $invoice,
        public readonly Payment $payment,
    ) {}
}
