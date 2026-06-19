<?php

declare(strict_types=1);

namespace Meteric\Facades;

use Brick\Money\Money;
use Illuminate\Support\Facades\Facade;
use Meteric\Contracts\InvoiceDriver;
use Meteric\Models\BillingAccount;
use Meteric\Models\Invoice;
use Meteric\Models\Payment;

/**
 * @method static ?Invoice invoicePending(BillingAccount $account, ?string $currency = null)
 * @method static ?Invoice invoiceConsolidated(BillingAccount $payer, ?string $currency = null)
 * @method static \Meteric\Models\Commitment commit(\Meteric\Models\SubscriptionItem $item, \Meteric\Enums\Interval $termInterval, int $termCount, Money $upfront, Money $rate, array $earlyTerm = [], ?\Carbon\CarbonImmutable $at = null)
 * @method static Money terminateCommitment(\Meteric\Models\Commitment $commitment, ?\Carbon\CarbonImmutable $at = null)
 * @method static Payment recordPayment(Invoice $invoice, Money $amount, ?string $reference = null)
 * @method static \Meteric\Quoting\QuoteBuilder quote()
 * @method static \Meteric\Subscriptions\SubscriptionBuilder subscribe(?\Illuminate\Database\Eloquent\Model $customer = null)
 * @method static \Meteric\Subscriptions\SubscriptionBuilder checkout(?\Illuminate\Database\Eloquent\Model $customer = null)
 * @method static array renew(\Meteric\Models\Subscription $sub, ?\Carbon\CarbonImmutable $at = null)
 * @method static \Meteric\Models\SubscriptionItem changePlan(\Meteric\Models\SubscriptionItem $item, \Meteric\Models\Price $newPrice, ?\Meteric\Enums\DowngradePolicy $downgrade = null, ?\Carbon\CarbonImmutable $at = null)
 * @method static \Meteric\Models\Subscription cancel(\Meteric\Models\Subscription $sub, string $at = 'period_end', ?\Carbon\CarbonImmutable $when = null)
 * @method static \Meteric\Models\Addon addAddon(\Meteric\Models\SubscriptionItem $item, \Meteric\Models\Price $price, ?string $group = null, float $qty = 1, ?\Carbon\CarbonImmutable $at = null)
 * @method static \Meteric\Models\ItemOption setOption(\Meteric\Models\SubscriptionItem $item, string $key, string $value, string $type, ?\Meteric\Models\Price $price = null, float $qty = 1, ?\Carbon\CarbonImmutable $at = null)
 * @method static \Meteric\Models\SubscriptionItem setQuantity(\Meteric\Models\SubscriptionItem $item, float $qty, ?\Carbon\CarbonImmutable $at = null)
 * @method static \Meteric\Models\UsageRecord recordUsage(\Meteric\Models\SubscriptionItem $item, string $dimension, float $quantity, ?\Carbon\CarbonImmutable $occurredAt = null, ?string $key = null)
 * @method static array rollupUsage(\Meteric\Models\SubscriptionItem $item, \Meteric\Support\Period $period)
 * @method static InvoiceDriver driver()
 *
 * @see \Meteric\Meteric
 */
final class Meteric extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Meteric\Meteric::class;
    }
}
