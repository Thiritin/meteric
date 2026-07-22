<?php

declare(strict_types=1);

namespace Meteric\Subscriptions;

use Meteric\Models\Invoice;
use Meteric\Models\Subscription;

/** Result of a checkout: the created subscription + the invoice billed now. */
final class SubscriptionResult
{
    public function __construct(
        public readonly Subscription $subscription,
        public readonly ?Invoice $invoice,
    ) {}
}
