<?php

declare(strict_types=1);

namespace Meteric\Invoicing;

use Illuminate\Support\Collection;
use Meteric\Models\BillingAccount;
use Meteric\Models\Charge;

/**
 * What a driver needs to emit an invoice: the payer, the charges being billed,
 * and a deterministic idempotency key so a retry never double-issues.
 *
 * @property Collection<int,Charge> $charges
 */
final class InvoiceDraft
{
    /** @param Collection<int,Charge> $charges */
    public function __construct(
        public readonly BillingAccount $account,
        public readonly string $currency,
        public readonly Collection $charges,
        public readonly string $idempotencyKey,
        public readonly array $meta = [],
    ) {}
}
