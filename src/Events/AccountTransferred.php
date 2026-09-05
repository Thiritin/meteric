<?php

declare(strict_types=1);

namespace Meteric\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Meteric\Models\BillingAccount;

/**
 * Every billing record on one account now belongs to another. The counts are by
 * table, zeros included, so a listener can reconcile without re-counting.
 */
final class AccountTransferred
{
    use Dispatchable;

    /** @param array<string, int> $moved */
    public function __construct(
        public readonly BillingAccount $from,
        public readonly BillingAccount $to,
        public readonly array $moved,
    ) {}
}
