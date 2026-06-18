<?php

declare(strict_types=1);

namespace Billify;

use Billify\Contracts\InvoiceDriver;
use Billify\Enums\InvoiceState;
use Billify\Invoicing\InvoiceDraft;
use Billify\Models\BillingAccount;
use Billify\Models\Charge;
use Billify\Models\Invoice;
use Billify\Models\Payment;
use Billify\Models\PaymentAllocation;
use Billify\Quoting\QuoteBuilder;
use Billify\Subscriptions\SubscriptionBuilder;
use Brick\Money\Money;
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

        /** @var Collection<int,Charge> $charges */
        $charges = Charge::query()
            ->pending()
            ->where('account_id', $account->id)
            ->where('currency', $currency)
            ->orderBy('created_at')
            ->get();

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
