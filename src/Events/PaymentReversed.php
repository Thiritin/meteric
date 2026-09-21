<?php

declare(strict_types=1);

namespace Meteric\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Meteric\Models\Invoice;
use Meteric\Models\Payment;
use Meteric\Models\PaymentAllocation;

/**
 * Money that was applied to an invoice has been taken back, and the invoice is
 * payable again with its charges unsettled.
 *
 * This is not a refund. Nothing was returned to the buyer and nothing was
 * credited: the receivable stands and whatever the host does about an unpaid
 * invoice applies to it again. Listen here to undo what listening to
 * `InvoicePaid` did.
 *
 * `$reversal` is the negative allocation this operation wrote. It is unique to
 * the reversal, so a listener that must run once has an identifier to key on.
 */
final class PaymentReversed
{
    use Dispatchable;

    public function __construct(
        public readonly Payment $payment,
        public readonly Invoice $invoice,
        public readonly string $reason,
        public readonly PaymentAllocation $reversal,
    ) {}
}
