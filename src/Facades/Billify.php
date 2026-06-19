<?php

declare(strict_types=1);

namespace Billify\Facades;

use Billify\Contracts\InvoiceDriver;
use Billify\Models\BillingAccount;
use Billify\Models\Invoice;
use Billify\Models\Payment;
use Brick\Money\Money;
use Illuminate\Support\Facades\Facade;

/**
 * @method static ?Invoice invoicePending(BillingAccount $account, ?string $currency = null)
 * @method static ?Invoice invoiceConsolidated(BillingAccount $payer, ?string $currency = null)
 * @method static \Billify\Models\Commitment commit(\Billify\Models\SubscriptionItem $item, \Billify\Enums\Interval $termInterval, int $termCount, Money $upfront, Money $rate, array $earlyTerm = [], ?\Carbon\CarbonImmutable $at = null)
 * @method static Money terminateCommitment(\Billify\Models\Commitment $commitment, ?\Carbon\CarbonImmutable $at = null)
 * @method static Payment recordPayment(Invoice $invoice, Money $amount, ?string $reference = null)
 * @method static \Billify\Quoting\QuoteBuilder quote()
 * @method static \Billify\Subscriptions\SubscriptionBuilder subscribe(?\Illuminate\Database\Eloquent\Model $customer = null)
 * @method static \Billify\Subscriptions\SubscriptionBuilder checkout(?\Illuminate\Database\Eloquent\Model $customer = null)
 * @method static array renew(\Billify\Models\Subscription $sub, ?\Carbon\CarbonImmutable $at = null)
 * @method static \Billify\Models\SubscriptionItem changePlan(\Billify\Models\SubscriptionItem $item, \Billify\Models\Price $newPrice, ?\Billify\Enums\DowngradePolicy $downgrade = null, ?\Carbon\CarbonImmutable $at = null)
 * @method static \Billify\Models\Subscription cancel(\Billify\Models\Subscription $sub, string $at = 'period_end', ?\Carbon\CarbonImmutable $when = null)
 * @method static \Billify\Models\Addon addAddon(\Billify\Models\SubscriptionItem $item, \Billify\Models\Price $price, ?string $group = null, float $qty = 1, ?\Carbon\CarbonImmutable $at = null)
 * @method static \Billify\Models\ItemOption setOption(\Billify\Models\SubscriptionItem $item, string $key, string $value, string $type, ?\Billify\Models\Price $price = null, float $qty = 1, ?\Carbon\CarbonImmutable $at = null)
 * @method static \Billify\Models\SubscriptionItem setQuantity(\Billify\Models\SubscriptionItem $item, float $qty, ?\Carbon\CarbonImmutable $at = null)
 * @method static \Billify\Models\UsageRecord recordUsage(\Billify\Models\SubscriptionItem $item, string $dimension, float $quantity, ?\Carbon\CarbonImmutable $occurredAt = null, ?string $key = null)
 * @method static array rollupUsage(\Billify\Models\SubscriptionItem $item, \Billify\Support\Period $period)
 * @method static InvoiceDriver driver()
 *
 * @see \Billify\Billify
 */
final class Billify extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Billify\Billify::class;
    }
}
