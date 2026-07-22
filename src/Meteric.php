<?php

declare(strict_types=1);

namespace Meteric;

use Brick\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Meteric\Contracts\InvoiceDriver;
use Meteric\Enums\DowngradePolicy;
use Meteric\Enums\LineKind;
use Meteric\Enums\UpgradePolicy;
use Meteric\Invoicing\InvoiceManager;
use Meteric\Models\Addon;
use Meteric\Models\BillingAccount;
use Meteric\Models\Charge;
use Meteric\Models\CreditNote;
use Meteric\Models\Invoice;
use Meteric\Models\InvoiceLine;
use Meteric\Models\ItemOption;
use Meteric\Models\Order;
use Meteric\Models\Payment;
use Meteric\Models\Price;
use Meteric\Models\ProductOptionValue;
use Meteric\Models\Subscription;
use Meteric\Models\SubscriptionItem;
use Meteric\Models\UsageRecord;
use Meteric\Quoting\QuoteBuilder;
use Meteric\Subscriptions\ItemManager;
use Meteric\Subscriptions\OrderBuilder;
use Meteric\Subscriptions\OrderManager;
use Meteric\Subscriptions\SubscriptionBuilder;
use Meteric\Subscriptions\SubscriptionManager;
use Meteric\Support\Models;
use Meteric\Support\Period;
use Meteric\Tax\Vies\Vies;
use Meteric\Tax\Vies\ViesResult;
use Meteric\Usage\UsageRollup;

/**
 * Package entrypoint. Resolved from the container; exposed via the Meteric
 * facade. Every operation delegates to its domain manager — invoicing lives in
 * InvoiceManager, lifecycle in SubscriptionManager, mutations in ItemManager,
 * orders in OrderManager, and metering in UsageRollup.
 */
final class Meteric
{
    public function __construct(private InvoiceManager $invoices) {}

    /**
     * Register host-app subclasses for Meteric models. Each override must extend
     * the model it replaces. Call once, e.g. in a service provider's register().
     *
     * @param  class-string<Model>  $override
     */
    public static function useModel(string $base, string $override): void
    {
        Models::swap($base, $override);
    }

    public static function useAccountModel(string $override): void
    {
        Models::swap(BillingAccount::class, $override);
    }

    public static function useSubscriptionModel(string $override): void
    {
        Models::swap(Subscription::class, $override);
    }

    public static function useChargeModel(string $override): void
    {
        Models::swap(Charge::class, $override);
    }

    public static function useInvoiceModel(string $override): void
    {
        Models::swap(Invoice::class, $override);
    }

    public static function usePaymentModel(string $override): void
    {
        Models::swap(Payment::class, $override);
    }

    public static function useCreditNoteModel(string $override): void
    {
        Models::swap(CreditNote::class, $override);
    }

    public static function useOrderModel(string $override): void
    {
        Models::swap(Order::class, $override);
    }

    public static function useUsageRecordModel(string $override): void
    {
        Models::swap(UsageRecord::class, $override);
    }

    /** Add a one-off custom charge to an account's pending pool. */
    public function charge(BillingAccount $account, Money $amount, string $title, ?string $group = null, ?string $description = null, LineKind $kind = LineKind::OneOff): Charge
    {
        return $this->invoices->charge($account, $amount, $title, $group, $description, $kind);
    }

    /** Bill an account's pending charges (one currency) into an invoice. */
    public function invoicePending(BillingAccount $account, ?string $currency = null): ?Invoice
    {
        return $this->invoices->invoicePending($account, $currency);
    }

    /**
     * Bill every currency that has pending charges (one invoice per currency).
     *
     * @return list<Invoice>
     */
    public function invoiceAllPending(BillingAccount $account): array
    {
        return $this->invoices->invoiceAllPending($account);
    }

    /** Bill the payer's own + child accounts' pending charges onto one invoice. */
    public function invoiceConsolidated(BillingAccount $payer, ?string $currency = null): ?Invoice
    {
        return $this->invoices->invoiceConsolidated($payer, $currency);
    }

    /** Issue a credit note against an invoice (the accounting reversal). */
    public function creditNote(Invoice $invoice, Money $amount, ?string $reason = null): CreditNote
    {
        return $this->invoices->creditNote($invoice, $amount, $reason);
    }

    /** Void an issued, unpaid invoice; its charges return to the billable pool. */
    public function voidInvoice(Invoice $invoice): Invoice
    {
        return $this->invoices->voidInvoice($invoice);
    }

    /** Open an empty editable Draft invoice (add lines by hand, then finalize). */
    public function createInvoice(BillingAccount $account, ?string $currency = null): Invoice
    {
        return $this->invoices->createInvoice($account, $currency);
    }

    /** Open an editable Draft invoice built from the account's pending charges. */
    public function draftInvoice(BillingAccount $account, ?string $currency = null): Invoice
    {
        return $this->invoices->draftInvoice($account, $currency);
    }

    /** Clone an invoice's header + lines into a fresh Draft (re-issue flow). */
    public function copyInvoice(Invoice $source): Invoice
    {
        return $this->invoices->copyInvoice($source);
    }

    /** Add a manual top-level line to a Draft invoice. */
    public function addLine(Invoice $invoice, string $title, Money $amount, ?string $description = null, ?string $group = null, LineKind $kind = LineKind::OneOff): InvoiceLine
    {
        return $this->invoices->addLine($invoice, $title, $amount, $description, $group, $kind);
    }

    /** Add a manual sub-line nested under an existing Draft invoice line. */
    public function addSubLine(InvoiceLine $parent, string $title, Money $amount, ?string $description = null, LineKind $kind = LineKind::Option): InvoiceLine
    {
        return $this->invoices->addSubLine($parent, $title, $amount, $description, $kind);
    }

    /** Remove a line from a Draft invoice (sub-lines cascade away). */
    public function removeLine(InvoiceLine $line): void
    {
        $this->invoices->removeLine($line);
    }

    /** Finalize a Draft invoice: send via the driver, set due date, fire InvoiceIssued. */
    public function finalizeInvoice(Invoice $draft): Invoice
    {
        return $this->invoices->finalizeInvoice($draft);
    }

    /** Record an inbound payment against an invoice and advance its state. */
    public function recordPayment(Invoice $invoice, Money $amount, ?string $reference = null): Payment
    {
        return $this->invoices->recordPayment($invoice, $amount, $reference);
    }

    public function driver(): InvoiceDriver
    {
        return $this->invoices->driver();
    }

    /** Start a read-only quote (checkout rendering). No persistence. */
    public function quote(): QuoteBuilder
    {
        return app(QuoteBuilder::class);
    }

    /**
     * Qualified VIES check: validates an EU VAT id and, when trader details are
     * passed, returns VIES's registered name/address and per-field match flags
     * for a "details do not match" warning. The consultationNumber is your audit
     * reference. Tax computation uses the resolvers' own VIES check; this is for
     * the UI warning and the record.
     *
     * @param  array<string,string>  $trader  name, companyType, street, postalCode, city
     * @param  array<string,string>  $requester  countryCode, vatNumber
     */
    public function viesCheck(string $countryCode, string $vatNumber, array $trader = [], array $requester = []): ViesResult
    {
        return app(Vies::class)->check($countryCode, $vatNumber, $trader, $requester);
    }

    /** Begin a subscription. Pass the billable customer model. */
    public function subscribe(?Model $customer = null): SubscriptionBuilder
    {
        $builder = app(SubscriptionBuilder::class);

        return $customer ? $builder->for($customer) : $builder;
    }

    /**
     * Open a persisted, immutable order. Build the cart with add()/addon()/
     * option(), end with ->create() to store a pending Order, then pay or confirm
     * it later. No Subscription/Charge/Invoice exists until the order is paid.
     */
    public function createOrder(?Model $customer = null): OrderBuilder
    {
        $builder = app(OrderBuilder::class);

        return $customer ? $builder->for($customer) : $builder;
    }

    /** Pay an order in full and materialize its subscription + Paid invoice. */
    public function payOrder(Order $order, Money $amount, ?string $ref = null): Order
    {
        return app(OrderManager::class)->pay($order, $amount, $ref);
    }

    /** Convert a zero-total order with no payment (e.g. a fully trialed signup). */
    public function confirmOrder(Order $order): Order
    {
        return app(OrderManager::class)->confirm($order);
    }

    /** Cancel a pending order. No-op once terminal. */
    public function cancelOrder(Order $order): Order
    {
        return app(OrderManager::class)->cancel($order);
    }

    /** Expire pending orders past their expiry. Returns the count. */
    public function expireOrders(?CarbonImmutable $at = null): int
    {
        return app(OrderManager::class)->expireDue($at);
    }

    /** Accrue the next cycle for all due items of a subscription (idempotent). */
    public function renew(Subscription $sub, ?CarbonImmutable $at = null): array
    {
        return app(SubscriptionManager::class)->renew($sub, $at);
    }

    /** Switch an item's plan. Upgrade → prorated charge now; downgrade → defer or discard. */
    public function changePlan(SubscriptionItem $item, Price $newPrice, ?DowngradePolicy $downgrade = null, ?UpgradePolicy $upgrade = null, ?CarbonImmutable $at = null): SubscriptionItem
    {
        return app(SubscriptionManager::class)->changePlan($item, $newPrice, $downgrade, $upgrade, $at);
    }

    /**
     * Cancel a subscription: 'now', 'period_end', or a specific boundary date.
     * $meta stores optional cancellation data (e.g. a reason) on the subscription.
     *
     * @param  array<string,mixed>  $meta
     */
    public function cancel(Subscription $sub, string|CarbonImmutable $at = 'period_end', ?CarbonImmutable $when = null, array $meta = []): Subscription
    {
        return app(SubscriptionManager::class)->cancel($sub, $at, $when, $meta);
    }

    /** The next cancellable term boundaries that satisfy the notice window. */
    public function cancellationOptions(Subscription $sub, int $count = 3): array
    {
        return app(SubscriptionManager::class)->cancellationOptions($sub, $count);
    }

    /** Enact scheduled cancellations whose boundary has passed. Run via meteric:run. */
    public function processDueCancellations(?CarbonImmutable $at = null): int
    {
        return app(SubscriptionManager::class)->processDueCancellations($at);
    }

    /** Suspend billing (state → paused). renew() accrues nothing while paused. */
    public function pause(Subscription $sub): Subscription
    {
        return app(SubscriptionManager::class)->pause($sub);
    }

    /** Resume billing (state → active) from $at (defaults to now). */
    public function resume(Subscription $sub, ?CarbonImmutable $at = null): Subscription
    {
        return app(SubscriptionManager::class)->resume($sub, $at);
    }

    /** Mark overdue invoices past_due and fire InvoiceOverdue. Returns count. */
    public function markOverdue(?CarbonImmutable $at = null): int
    {
        return app(SubscriptionManager::class)->markOverdue($at);
    }

    /** Book an addon on an item (prorated). Group members are swapped. */
    public function addAddon(SubscriptionItem $item, Price $price, ?string $group = null, float $qty = 1, ?CarbonImmutable $at = null): Addon
    {
        return app(ItemManager::class)->addAddon($item, $price, $group, $qty, $at);
    }

    /** Remove an addon mid-cycle with a prorated credit. */
    public function removeAddon(Addon $addon, ?CarbonImmutable $at = null): void
    {
        app(ItemManager::class)->removeAddon($addon, $at);
    }

    /** Set a configurable option (e.g. slots) on an item, prorating the delta. */
    public function setOption(SubscriptionItem $item, string $key, string $value, string $type, ?Price $price = null, float $qty = 1, ?CarbonImmutable $at = null, ?float $min = null, ?float $max = null, ?string $label = null): ItemOption
    {
        return app(ItemManager::class)->setOption($item, $key, $value, $type, $price, $qty, $at, $min, $max, $label);
    }

    /** Change an item's base quantity, prorating the difference. */
    public function setQuantity(SubscriptionItem $item, float $qty, ?CarbonImmutable $at = null): SubscriptionItem
    {
        return app(ItemManager::class)->setQuantity($item, $qty, $at);
    }

    /** Select a catalog product-option value (resolves its price + bounds). */
    public function chooseOption(SubscriptionItem $item, ProductOptionValue $value, float $qty = 1, ?CarbonImmutable $at = null): ItemOption
    {
        return app(ItemManager::class)->chooseOption($item, $value, $qty, $at);
    }

    /** The current billing cycle window for an item (query your usage API for this range). */
    public function billingCycle(SubscriptionItem $item): ?Period
    {
        return $item->billingCycle();
    }

    /** Report metered usage for an item's dimension (idempotent on $key). */
    public function recordUsage(SubscriptionItem $item, string $dimension, float $quantity, ?CarbonImmutable $occurredAt = null, ?string $key = null): UsageRecord
    {
        return app(UsageRollup::class)->record($item, $dimension, $quantity, $occurredAt, $key);
    }

    /** Roll up an item's usage window into in-arrears charges. */
    public function rollupUsage(SubscriptionItem $item, Period $period): array
    {
        return app(UsageRollup::class)->rollup($item, $period);
    }
}
