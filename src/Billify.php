<?php

declare(strict_types=1);

namespace Billify;

use Billify\Contracts\InvoiceDriver;
use Billify\Enums\DowngradePolicy;
use Billify\Enums\Interval;
use Billify\Enums\InvoiceState;
use Billify\Invoicing\InvoiceDraft;
use Billify\Models\Addon;
use Billify\Models\BillingAccount;
use Billify\Models\Charge;
use Billify\Models\Commitment;
use Billify\Models\Invoice;
use Billify\Models\ItemOption;
use Billify\Models\Payment;
use Billify\Models\PaymentAllocation;
use Billify\Models\Price;
use Billify\Models\Subscription;
use Billify\Models\SubscriptionItem;
use Billify\Models\UsageRecord;
use Billify\Quoting\QuoteBuilder;
use Billify\Subscriptions\CommitmentManager;
use Billify\Subscriptions\ItemManager;
use Billify\Subscriptions\SubscriptionBuilder;
use Billify\Subscriptions\SubscriptionManager;
use Billify\Support\Period;
use Billify\Usage\UsageRollup;
use Brick\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Package entrypoint. Resolved from the container; exposed via the Billify facade.
 *
 * The invoicing flow is the heart of the charge-vs-invoice guarantee:
 * charges accrue as `pending`, and only a confirmed driver success flips them to
 * `invoiced`. A driver failure leaves everything `pending` for the next run.
 */
final class Billify
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

        return Invoice::find($issued->invoiceId);
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
        return DB::transaction(function () use ($invoice, $amount, $reference): Payment {
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

    /** Accrue the next cycle for all due items of a subscription (idempotent). */
    public function renew(Subscription $sub, ?CarbonImmutable $at = null): array
    {
        return app(SubscriptionManager::class)->renew($sub, $at);
    }

    /** Switch an item's plan. Upgrade → prorated charge now; downgrade → defer or discard. */
    public function changePlan(SubscriptionItem $item, Price $newPrice, ?DowngradePolicy $downgrade = null, ?CarbonImmutable $at = null): SubscriptionItem
    {
        return app(SubscriptionManager::class)->changePlan($item, $newPrice, $downgrade, $at);
    }

    /** Cancel a subscription: 'period_end' or 'now'. */
    public function cancel(Subscription $sub, string $at = 'period_end', ?CarbonImmutable $when = null): Subscription
    {
        return app(SubscriptionManager::class)->cancel($sub, $at, $when);
    }

    /** Book an addon on an item (prorated). Group members are swapped. */
    public function addAddon(SubscriptionItem $item, Price $price, ?string $group = null, float $qty = 1, ?CarbonImmutable $at = null): Addon
    {
        return app(ItemManager::class)->addAddon($item, $price, $group, $qty, $at);
    }

    /** Set a configurable option (e.g. slots) on an item, prorating the delta. */
    public function setOption(SubscriptionItem $item, string $key, string $value, string $type, ?Price $price = null, float $qty = 1, ?CarbonImmutable $at = null): ItemOption
    {
        return app(ItemManager::class)->setOption($item, $key, $value, $type, $price, $qty, $at);
    }

    /** Change an item's base quantity, prorating the difference. */
    public function setQuantity(SubscriptionItem $item, float $qty, ?CarbonImmutable $at = null): SubscriptionItem
    {
        return app(ItemManager::class)->setQuantity($item, $qty, $at);
    }

    /** Add a term commitment (upfront + reduced rate) to an item. */
    public function commit(SubscriptionItem $item, Interval $termInterval, int $termCount, Money $upfront, Money $rate, array $earlyTerm = [], ?CarbonImmutable $at = null): Commitment
    {
        return app(CommitmentManager::class)->commit($item, $termInterval, $termCount, $upfront, $rate, $earlyTerm, $at);
    }

    /** Terminate a commitment early; returns the fee charged. */
    public function terminateCommitment(Commitment $commitment, ?CarbonImmutable $at = null): Money
    {
        return app(CommitmentManager::class)->terminate($commitment, $at);
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
