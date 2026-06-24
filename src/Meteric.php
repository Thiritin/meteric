<?php

declare(strict_types=1);

namespace Meteric;

use Brick\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Meteric\Contracts\InvoiceDriver;
use Meteric\Enums\DowngradePolicy;
use Meteric\Enums\InvoiceState;
use Meteric\Enums\UpgradePolicy;
use Meteric\Events\CreditNoteIssued;
use Meteric\Events\InvoiceIssued;
use Meteric\Events\InvoicePaid;
use Meteric\Events\InvoicePartiallyPaid;
use Meteric\Events\InvoiceVoided;
use Meteric\Invoicing\CreditNoteDraft;
use Meteric\Invoicing\InvoiceDraft;
use Meteric\Invoicing\IssuedInvoice;
use Meteric\Models\Addon;
use Meteric\Models\BillingAccount;
use Meteric\Models\Charge;
use Meteric\Models\CreditNote;
use Meteric\Models\Invoice;
use Meteric\Models\ItemOption;
use Meteric\Models\Order;
use Meteric\Models\Payment;
use Meteric\Models\PaymentAllocation;
use Meteric\Models\Price;
use Meteric\Models\ProductOptionValue;
use Meteric\Models\Subscription;
use Meteric\Models\SubscriptionItem;
use Meteric\Models\UsageRecord;
use Meteric\Quoting\QuoteBuilder;
use Meteric\Subscriptions\CheckoutBuilder;
use Meteric\Subscriptions\CheckoutManager;
use Meteric\Subscriptions\ItemManager;
use Meteric\Subscriptions\SubscriptionBuilder;
use Meteric\Subscriptions\SubscriptionManager;
use Meteric\Support\Period;
use Meteric\Usage\UsageRollup;
use Throwable;

/**
 * Package entrypoint. Resolved from the container; exposed via the Meteric facade.
 *
 * The invoicing flow is the heart of the charge-vs-invoice guarantee:
 * charges accrue as `pending`, and only a confirmed driver success flips them to
 * `invoiced`. A driver failure leaves everything `pending` for the next run.
 */
final class Meteric
{
    public function __construct(private InvoiceDriver $driver) {}

    /**
     * Collect an account's pending charges (one currency) and issue them via the
     * bound driver. Returns the issued Invoice, or null when nothing is pending.
     *
     * @throws Throwable Re-thrown from the driver; charges remain `pending`.
     */
    public function invoicePending(BillingAccount $account, ?string $currency = null): ?Invoice
    {
        $currency ??= $account->currency;
        $charges = $this->pendingCharges([$account->id], $currency);

        return $this->issue($account, $currency, $charges);
    }

    /**
     * Consolidated invoice: bill the payer's own + all child accounts' pending
     * charges onto a single invoice (AWS org / reseller). Itemized per account.
     */
    public function invoiceConsolidated(BillingAccount $payer, ?string $currency = null): ?Invoice
    {
        $currency ??= $payer->currency;
        $charges = $this->pendingCharges($payer->payerScopeIds(), $currency);

        return $this->issue($payer, $currency, $charges);
    }

    /** @param  Collection<int,Charge>  $charges */
    private function issue(BillingAccount $account, string $currency, Collection $charges): ?Invoice
    {
        if ($charges->isEmpty()) {
            return null;
        }

        // An invoice is never negative. If pending credits outweigh the charges,
        // hold everything: the credit lines stay pending and reduce a later
        // invoice once new charges land. A refund (money back) is a credit note,
        // not a negative invoice.
        if ((int) $charges->sum('amount_minor') < 0) {
            return null;
        }

        $draft = new InvoiceDraft(
            account: $account,
            currency: $currency,
            charges: $charges,
            idempotencyKey: $this->batchKey($charges),
        );

        // Driver call is the failure boundary. If it throws, nothing below runs.
        $issued = $this->driver->issue($draft);

        // Confirmed success → flip charges atomically.
        DB::transaction(function () use ($charges, $issued): void {
            $invoice = Invoice::findOrFail($issued->invoiceId);
            foreach ($charges as $charge) {
                $charge->markInvoiced($invoice);
            }
        });

        $invoice = Invoice::findOrFail($issued->invoiceId);
        if ($invoice->due_at === null) {
            $net = (int) config('meteric.invoice.net_days', 14);
            $invoice->forceFill(['due_at' => ($invoice->issued_at ?? now())->addDays($net)])->save();
        }

        InvoiceIssued::dispatch($invoice);

        return $invoice;
    }

    /**
     * Issue a credit note against an invoice. This is the accounting reversal for
     * a correction or a refund. The actual money return is your gateway's job.
     */
    public function creditNote(Invoice $invoice, Money $amount, ?string $reason = null): CreditNote
    {
        $issued = new IssuedInvoice($invoice->id, $invoice->number, $invoice->external_id, $invoice->external_url);
        $result = $this->driver->creditNote($issued, new CreditNoteDraft($amount, $reason));
        $note = CreditNote::findOrFail($result->creditNoteId);
        CreditNoteIssued::dispatch($note);

        return $note;
    }

    /** Void an issued, unpaid invoice. */
    public function voidInvoice(Invoice $invoice): Invoice
    {
        if ($invoice->paid_minor > 0) {
            throw new \LogicException('Cannot void an invoice with payments. Issue a credit note instead.');
        }

        $invoice->forceFill(['state' => InvoiceState::Void])->save();
        InvoiceVoided::dispatch($invoice);

        return $invoice;
    }

    /**
     * @param  list<string>  $accountIds
     * @return Collection<int,Charge>
     */
    private function pendingCharges(array $accountIds, string $currency): Collection
    {
        return Charge::query()
            ->pending()
            ->whereIn('account_id', $accountIds)
            ->where('currency', $currency)
            ->orderBy('created_at')
            ->get();
    }

    /** Record an inbound payment against an invoice and advance its state. */
    public function recordPayment(Invoice $invoice, Money $amount, ?string $reference = null): Payment
    {
        $payment = DB::transaction(function () use ($invoice, $amount, $reference): Payment {
            $payment = Payment::create([
                'account_id' => $invoice->account_id,
                'amount_minor' => $amount->getMinorAmount()->toInt(),
                'currency' => $amount->getCurrency()->getCurrencyCode(),
                'reference' => $reference,
            ]);

            PaymentAllocation::create([
                'payment_id' => $payment->id,
                'invoice_id' => $invoice->id,
                'amount_minor' => $amount->getMinorAmount()->toInt(),
            ]);

            $paid = $invoice->paid_minor + $amount->getMinorAmount()->toInt();
            $invoice->forceFill([
                'paid_minor' => $paid,
                'state' => $paid >= $invoice->total_minor ? InvoiceState::Paid : InvoiceState::PartiallyPaid,
                'paid_at' => $paid >= $invoice->total_minor ? now() : $invoice->paid_at,
            ])->save();

            return $payment;
        });

        if ($invoice->state === InvoiceState::Paid) {
            InvoicePaid::dispatch($invoice, $payment);
        } else {
            InvoicePartiallyPaid::dispatch($invoice, $payment);
        }

        return $payment;
    }

    /** Start a read-only quote (checkout rendering). No persistence. */
    public function quote(): QuoteBuilder
    {
        return app(QuoteBuilder::class);
    }

    /** Begin a subscription. Pass the billable customer model. */
    public function subscribe(?Model $customer = null): SubscriptionBuilder
    {
        $builder = app(SubscriptionBuilder::class);

        return $customer ? $builder->for($customer) : $builder;
    }

    /** Begin a checkout — subscribe then immediately invoice. End with ->checkout(). */
    public function checkout(?Model $customer = null): SubscriptionBuilder
    {
        return $this->subscribe($customer);
    }

    /**
     * Open a persisted, immutable order (a frozen checkout). Build the cart with
     * add()/addon()/option(), end with ->create() to store a pending Order, then
     * pay or confirm it later. No Subscription/Charge/Invoice exists until paid.
     */
    public function openCheckout(?Model $customer = null): CheckoutBuilder
    {
        $builder = app(CheckoutBuilder::class);

        return $customer ? $builder->for($customer) : $builder;
    }

    /** Pay an order in full and materialize its subscription + Paid invoice. */
    public function payCheckout(Order $order, Money $amount, ?string $ref = null): Order
    {
        return app(CheckoutManager::class)->pay($order, $amount, $ref);
    }

    /** Convert a zero-total order with no payment (e.g. a fully trialed signup). */
    public function confirmCheckout(Order $order): Order
    {
        return app(CheckoutManager::class)->confirm($order);
    }

    /** Cancel a pending order. No-op once terminal. */
    public function cancelCheckout(Order $order): Order
    {
        return app(CheckoutManager::class)->cancel($order);
    }

    /** Expire pending orders past their expiry. Returns the count. */
    public function expireCheckouts(?CarbonImmutable $at = null): int
    {
        return app(CheckoutManager::class)->expireDue($at);
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

    public function driver(): InvoiceDriver
    {
        return $this->driver;
    }

    /** Deterministic batch key so a retried run reuses the same invoice. */
    private function batchKey(Collection $charges): string
    {
        return 'batch_'.substr(hash('sha256', $charges->pluck('id')->sort()->implode('|')), 0, 32);
    }
}
