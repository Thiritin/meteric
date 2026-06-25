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
 * @method static Payment recordPayment(Invoice $invoice, Money $amount, ?string $reference = null)
 * @method static \Meteric\Quoting\QuoteBuilder quote()
 * @method static \Meteric\Tax\Vies\ViesResult viesCheck(string $countryCode, string $vatNumber, array $trader = [], array $requester = [])
 * @method static \Meteric\Subscriptions\SubscriptionBuilder subscribe(?\Illuminate\Database\Eloquent\Model $customer = null)
 * @method static \Meteric\Subscriptions\SubscriptionBuilder checkout(?\Illuminate\Database\Eloquent\Model $customer = null)
 * @method static \Meteric\Subscriptions\CheckoutBuilder openCheckout(?\Illuminate\Database\Eloquent\Model $customer = null)
 * @method static \Meteric\Models\Order payCheckout(\Meteric\Models\Order $order, Money $amount, ?string $ref = null)
 * @method static \Meteric\Models\Order confirmCheckout(\Meteric\Models\Order $order)
 * @method static \Meteric\Models\Order cancelCheckout(\Meteric\Models\Order $order)
 * @method static int expireCheckouts(?\Carbon\CarbonImmutable $at = null)
 * @method static array renew(\Meteric\Models\Subscription $sub, ?\Carbon\CarbonImmutable $at = null)
 * @method static \Meteric\Models\SubscriptionItem changePlan(\Meteric\Models\SubscriptionItem $item, \Meteric\Models\Price $newPrice, ?\Meteric\Enums\DowngradePolicy $downgrade = null, ?\Meteric\Enums\UpgradePolicy $upgrade = null, ?\Carbon\CarbonImmutable $at = null)
 * @method static \Meteric\Models\Subscription cancel(\Meteric\Models\Subscription $sub, string|\Carbon\CarbonImmutable $at = 'period_end', ?\Carbon\CarbonImmutable $when = null, array $meta = [])
 * @method static array cancellationOptions(\Meteric\Models\Subscription $sub, int $count = 3)
 * @method static int processDueCancellations(?\Carbon\CarbonImmutable $at = null)
 * @method static \Meteric\Models\Subscription pause(\Meteric\Models\Subscription $sub)
 * @method static \Meteric\Models\Subscription resume(\Meteric\Models\Subscription $sub, ?\Carbon\CarbonImmutable $at = null)
 * @method static int markOverdue(?\Carbon\CarbonImmutable $at = null)
 * @method static Invoice voidInvoice(Invoice $invoice)
 * @method static Invoice createInvoice(BillingAccount $account, ?string $currency = null)
 * @method static Invoice draftInvoice(BillingAccount $account, ?string $currency = null)
 * @method static Invoice copyInvoice(Invoice $source)
 * @method static \Meteric\Models\InvoiceLine addLine(Invoice $invoice, string $title, Money $amount, ?string $description = null, ?string $group = null, \Meteric\Enums\LineKind $kind = \Meteric\Enums\LineKind::OneOff)
 * @method static \Meteric\Models\InvoiceLine addSubLine(\Meteric\Models\InvoiceLine $parent, string $title, Money $amount, ?string $description = null, \Meteric\Enums\LineKind $kind = \Meteric\Enums\LineKind::Option)
 * @method static void removeLine(\Meteric\Models\InvoiceLine $line)
 * @method static Invoice finalizeInvoice(Invoice $draft)
 * @method static \Meteric\Models\CreditNote creditNote(Invoice $invoice, Money $amount, ?string $reason = null)
 * @method static \Meteric\Models\Addon addAddon(\Meteric\Models\SubscriptionItem $item, \Meteric\Models\Price $price, ?string $group = null, float $qty = 1, ?\Carbon\CarbonImmutable $at = null)
 * @method static void removeAddon(\Meteric\Models\Addon $addon, ?\Carbon\CarbonImmutable $at = null)
 * @method static \Meteric\Models\ItemOption setOption(\Meteric\Models\SubscriptionItem $item, string $key, string $value, string $type, ?\Meteric\Models\Price $price = null, float $qty = 1, ?\Carbon\CarbonImmutable $at = null, ?float $min = null, ?float $max = null, ?string $label = null)
 * @method static \Meteric\Models\SubscriptionItem setQuantity(\Meteric\Models\SubscriptionItem $item, float $qty, ?\Carbon\CarbonImmutable $at = null)
 * @method static \Meteric\Models\ItemOption chooseOption(\Meteric\Models\SubscriptionItem $item, \Meteric\Models\ProductOptionValue $value, float $qty = 1, ?\Carbon\CarbonImmutable $at = null)
 * @method static ?\Meteric\Support\Period billingCycle(\Meteric\Models\SubscriptionItem $item)
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
