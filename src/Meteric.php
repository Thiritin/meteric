<?php

declare(strict_types=1);

namespace Meteric;

use Brick\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Meteric\Contracts\InvoiceDriver;
use Meteric\Enums\BillingMode;
use Meteric\Enums\ChargeState;
use Meteric\Enums\DowngradePolicy;
use Meteric\Enums\InvoiceState;
use Meteric\Enums\LineKind;
use Meteric\Enums\UpgradePolicy;
use Meteric\Events\CreditNoteIssued;
use Meteric\Events\InvoiceIssued;
use Meteric\Events\InvoicePaid;
use Meteric\Events\InvoicePartiallyPaid;
use Meteric\Events\InvoiceVoided;
use Meteric\Invoicing\CreditNoteDraft;
use Meteric\Invoicing\Drivers\DatabaseInvoiceDriver;
use Meteric\Invoicing\InvoiceDraft;
use Meteric\Invoicing\IssuedInvoice;
use Meteric\Models\Addon;
use Meteric\Models\BillingAccount;
use Meteric\Models\Charge;
use Meteric\Models\CreditNote;
use Meteric\Models\Invoice;
use Meteric\Models\InvoiceLine;
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
use Meteric\Tax\Vies\Vies;
use Meteric\Tax\Vies\ViesResult;
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

        // Read the pending charges and issue them in one transaction with the
        // rows locked, so two concurrent runs can never bill the same charge
        // onto two invoices. Under READ COMMITTED the FOR UPDATE re-check drops
        // any charge a competing run flipped to invoiced while we waited.
        return DB::transaction(function () use ($account, $currency): ?Invoice {
            $charges = $this->pendingCharges([$account->id], $currency);

            return $this->issue($account, $currency, $charges);
        });
    }

    /**
     * Consolidated invoice: bill the payer's own + all child accounts' pending
     * charges onto a single invoice (AWS org / reseller). Itemized per account.
     */
    public function invoiceConsolidated(BillingAccount $payer, ?string $currency = null): ?Invoice
    {
        $currency ??= $payer->currency;

        return DB::transaction(function () use ($payer, $currency): ?Invoice {
            $charges = $this->pendingCharges($payer->payerScopeIds(), $currency);

            return $this->issue($payer, $currency, $charges);
        });
    }

    /**
     * Add a one-off custom charge to an account. It accrues as `pending`, so the
     * next billing run (or invoicePending) bills it on the account's invoice. For
     * a standalone document right now, build one with createInvoice instead.
     */
    public function charge(BillingAccount $account, Money $amount, string $title, ?string $group = null, ?string $description = null, LineKind $kind = LineKind::OneOff): Charge
    {
        return Charge::create([
            'account_id' => $account->id,
            'origin_type' => 'manual',
            'origin_id' => (string) Str::uuid(),
            'kind' => $kind->value,
            'billing_mode' => BillingMode::InAdvance->value,
            'state' => ChargeState::Pending->value,
            'title' => $title,
            'group' => $group,
            'description' => $description,
            'quantity' => 1,
            'amount_minor' => $amount->getMinorAmount()->toInt(),
            'currency' => $amount->getCurrency()->getCurrencyCode(),
            'idempotency_key' => (string) Str::uuid(),
        ]);
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
            dueDays: (int) config('meteric.invoice.net_days', 14),
        );

        // Driver call is the failure boundary. If it throws, nothing below runs.
        // The driver builds the lines and flips each billed charge to invoiced.
        $issued = $this->driver->issue($draft);

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

    /**
     * Void (cancel) an issued, unpaid invoice: an invoice made in error, before
     * any money moved. Refuses if the invoice has a payment (issue a credit note
     * to reverse a paid invoice instead). Routes through the driver, which may
     * void a draft/remote record or refuse (lexoffice forbids voiding a finalized
     * document).
     *
     * Each charge this invoice's lines referenced returns to the billable pool
     * (pending) unless it still has a line on another non-void invoice, or it was
     * discarded (soft-deleted) or already settled. This is the never-lose-a-charge
     * half of the guarantee: voiding a wrong invoice re-bills its work.
     */
    public function voidInvoice(Invoice $invoice): Invoice
    {
        if ($invoice->paid_minor > 0) {
            throw new \LogicException('Cannot void an invoice with payments. Issue a credit note instead.');
        }

        // If the driver throws (e.g. lexoffice refusing a finalized document),
        // nothing below runs and the invoice is left untouched.
        $this->driver->void(new IssuedInvoice($invoice->id, $invoice->number, $invoice->external_id, $invoice->external_url));

        DB::transaction(function () use ($invoice): void {
            // Release the batch key so the same charge set can be re-billed onto
            // a fresh invoice once these charges revert to pending.
            $invoice->forceFill(['state' => InvoiceState::Void, 'idempotency_key' => null])->save();

            $chargeIds = $invoice->lines()->whereNotNull('charge_id')->pluck('charge_id')->unique();
            foreach (Charge::withTrashed()->whereIn('id', $chargeIds)->get() as $charge) {
                if (! $this->chargeHasLiveLine($charge)) {
                    $charge->revertToPending();
                }
            }
        });

        InvoiceVoided::dispatch($invoice->refresh());

        return $invoice;
    }

    /** Does this charge still have a line on a non-void invoice? */
    private function chargeHasLiveLine(Charge $charge): bool
    {
        return InvoiceLine::query()
            ->where('charge_id', $charge->id)
            ->whereHas('invoice', fn ($q) => $q->where('state', '<>', InvoiceState::Void->value))
            ->exists();
    }

    /**
     * Open an empty editable Draft invoice for an account: a header with no
     * charges, no lines, and zero totals. Add lines by hand with addLine /
     * addSubLine, then finalize with finalizeInvoice.
     */
    public function createInvoice(BillingAccount $account, ?string $currency = null): Invoice
    {
        return Invoice::create([
            'account_id' => $account->id,
            'customer_type' => $account->owner_type,
            'customer_id' => $account->owner_id,
            'driver' => config('meteric.invoice.driver', 'database'),
            'state' => InvoiceState::Draft,
            'currency' => $currency ?? $account->currency,
        ]);
    }

    /**
     * Open an editable Draft invoice for an account from its pending charges
     * (one currency): pulls them, builds the lines, and flips each to invoiced so
     * it leaves the pending pool. No document is sent and no due date or
     * InvoiceIssued event is set. An account with nothing pending yields an empty
     * draft with zero totals. Finalize it later with finalizeInvoice.
     */
    public function draftInvoice(BillingAccount $account, ?string $currency = null): Invoice
    {
        $currency ??= $account->currency;

        return DB::transaction(function () use ($account, $currency): Invoice {
            $invoice = $this->createInvoice($account, $currency);

            $charges = $this->pendingCharges([$account->id], $currency);
            $this->rebuildLines($invoice, $charges);

            return $invoice->refresh();
        });
    }

    /**
     * Re-issue an invoice: clone its header and lines into a fresh Draft. The
     * lines (and their sub-line hierarchy) come over keeping their charge_id, so
     * the same charges are billed; no charge is duplicated and no charge state
     * changes. The canonical wrong-address re-issue is
     * copyInvoice($source) -> voidInvoice($source) -> finalizeInvoice($copy).
     */
    public function copyInvoice(Invoice $source): Invoice
    {
        return DB::transaction(function () use ($source): Invoice {
            $copy = Invoice::create(array_filter([
                'account_id' => $source->account_id,
                'customer_type' => $source->customer_type,
                'customer_id' => $source->customer_id,
                'driver' => $source->driver,
                'state' => InvoiceState::Draft,
                'currency' => $source->currency,
                'metadata' => $source->metadata,
            ], fn ($v): bool => $v !== null));

            // Two-pass clone so a child never references a not-yet-cloned parent:
            // parents first (recording old id -> new id), then children remapped.
            $map = [];
            $lines = $source->lines()->orderBy('sort')->get();

            foreach ($lines->whereNull('parent_id') as $line) {
                $map[$line->id] = $this->cloneLine($line, $copy->id, null)->id;
            }
            foreach ($lines->whereNotNull('parent_id') as $line) {
                $this->cloneLine($line, $copy->id, $map[$line->parent_id] ?? null);
            }

            $this->recomputeTotals($copy);

            return $copy->refresh();
        });
    }

    /** Clone one invoice line onto $invoiceId under $parentId, keeping charge_id. */
    private function cloneLine(InvoiceLine $line, string $invoiceId, ?string $parentId): InvoiceLine
    {
        return InvoiceLine::create([
            'invoice_id' => $invoiceId,
            'charge_id' => $line->charge_id,
            'parent_id' => $parentId,
            'kind' => $line->kind,
            'title' => $line->title,
            'group' => $line->group,
            'line_group' => $line->line_group,
            'description' => $line->description,
            'quantity' => $line->quantity,
            'unit' => $line->unit,
            'unit_minor' => $line->unit_minor,
            'unit_rate' => $line->unit_rate,
            'amount_minor' => $line->amount_minor,
            'tax_rate' => $line->tax_rate,
            'tax_minor' => $line->tax_minor,
            'tax_label' => $line->tax_label,
            'currency' => $line->currency,
            'covers' => $line->covers,
            'dimension_id' => $line->dimension_id,
            'metadata' => $line->metadata,
            'sort' => $line->sort,
        ]);
    }

    /**
     * Add a manual top-level line to a Draft invoice (no charge behind it). The
     * line's tax is resolved from the account's context. Recomputes the totals.
     */
    public function addLine(Invoice $invoice, string $title, Money $amount, ?string $description = null, ?string $group = null, LineKind $kind = LineKind::OneOff): InvoiceLine
    {
        if ($invoice->state !== InvoiceState::Draft) {
            throw new \LogicException('Can only add lines to a draft invoice.');
        }

        return DB::transaction(function () use ($invoice, $title, $amount, $description, $group, $kind): InvoiceLine {
            $line = $this->writeManualLine($invoice, null, $title, $amount, $description, $group, $kind);
            $this->recomputeTotals($invoice);

            return $line;
        });
    }

    /**
     * Add a manual sub-line nested under an existing line on a Draft invoice.
     * Recomputes the totals (every line, parent and child, counts toward them).
     */
    public function addSubLine(InvoiceLine $parent, string $title, Money $amount, ?string $description = null, LineKind $kind = LineKind::Option): InvoiceLine
    {
        $invoice = $parent->invoice;
        if ($invoice->state !== InvoiceState::Draft) {
            throw new \LogicException('Can only add lines to a draft invoice.');
        }

        return DB::transaction(function () use ($invoice, $parent, $title, $amount, $description, $kind): InvoiceLine {
            $line = $this->writeManualLine($invoice, $parent->id, $title, $amount, $description, $parent->group, $kind);
            $this->recomputeTotals($invoice);

            return $line;
        });
    }

    /**
     * Remove a line from a Draft invoice (its sub-lines cascade away). If the line
     * was the charge's last live line, the charge returns to the billable pool.
     * Recomputes the totals.
     */
    public function removeLine(InvoiceLine $line): void
    {
        $invoice = $line->invoice;
        if ($invoice->state !== InvoiceState::Draft) {
            throw new \LogicException('Can only remove lines from a draft invoice.');
        }

        DB::transaction(function () use ($invoice, $line): void {
            $chargeIds = collect([$line->charge_id])
                ->merge($line->children()->pluck('charge_id'))
                ->filter()
                ->unique();

            $line->delete();   // cascades sub-lines

            foreach (Charge::withTrashed()->whereIn('id', $chargeIds)->get() as $charge) {
                if (! $this->chargeHasLiveLine($charge)) {
                    $charge->revertToPending();
                }
            }

            $this->recomputeTotals($invoice);
        });
    }

    /** Persist one manual line (no charge) with account-context tax. */
    private function writeManualLine(Invoice $invoice, ?string $parentId, string $title, Money $amount, ?string $description, ?string $group, LineKind $kind): InvoiceLine
    {
        $taxResult = app(DatabaseInvoiceDriver::class)->resolveTax($invoice, $amount);

        $next = (int) ($invoice->lines()->max('sort') ?? -1) + 1;

        return InvoiceLine::create([
            'invoice_id' => $invoice->id,
            'charge_id' => null,
            'parent_id' => $parentId,
            'kind' => $kind,
            'title' => $title,
            'group' => $group,
            'description' => $description,
            'quantity' => 1,
            'amount_minor' => $amount->getMinorAmount()->toInt(),
            'tax_rate' => $taxResult->rate,
            'tax_minor' => $taxResult->amount->getMinorAmount()->toInt(),
            'tax_label' => $taxResult->label,
            'currency' => $invoice->currency,
            'sort' => $next,
        ]);
    }

    /** Recompute a draft's totals from its current lines (manual edits). */
    private function recomputeTotals(Invoice $invoice): void
    {
        $subtotal = (int) $invoice->lines()->sum('amount_minor');
        $tax = (int) $invoice->lines()->sum('tax_minor');

        $invoice->forceFill([
            'subtotal_minor' => $subtotal,
            'tax_minor' => $tax,
            'total_minor' => $subtotal + $tax,
        ])->save();
    }

    /**
     * Finalize a Draft invoice: send its current lines via the driver, set the
     * due date (config meteric.invoice.net_days, default 14), and fire
     * InvoiceIssued. The charges are already invoiced and attached, so payment
     * and overdue tracking apply from here. A driver failure leaves the draft
     * untouched.
     */
    public function finalizeInvoice(Invoice $draft): Invoice
    {
        if ($draft->state !== InvoiceState::Draft) {
            throw new \LogicException('Only a draft invoice can be finalized.');
        }

        // Driver call is the failure boundary. If it throws, nothing below runs.
        $this->driver->finalize($draft);

        $invoice = $draft->refresh();
        if ($invoice->due_at === null) {
            $net = (int) config('meteric.invoice.net_days', 14);
            $invoice->forceFill(['due_at' => ($invoice->issued_at ?? now())->addDays($net)])->save();
        }

        $invoice->refresh();
        InvoiceIssued::dispatch($invoice);

        return $invoice;
    }

    /**
     * Build a draft invoice's lines from a set of charges (via the database
     * driver), flipping each billed charge to invoiced.
     *
     * @param  Collection<int,Charge>  $charges
     */
    private function rebuildLines(Invoice $invoice, Collection $charges): void
    {
        app(DatabaseInvoiceDriver::class)->rebuildLines($invoice, $charges);
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
            ->lockForUpdate()
            ->get();
    }

    /** Record an inbound payment against an invoice and advance its state. */
    public function recordPayment(Invoice $invoice, Money $amount, ?string $reference = null): Payment
    {
        $paymentCurrency = $amount->getCurrency()->getCurrencyCode();
        if ($paymentCurrency !== $invoice->currency) {
            throw new \InvalidArgumentException(
                "Payment currency {$paymentCurrency} does not match invoice currency {$invoice->currency}."
            );
        }

        $payment = DB::transaction(function () use ($invoice, $amount, $reference): Payment {
            // Lock the invoice row so concurrent payments (e.g. gateway webhook
            // retries) serialize instead of both reading a stale paid_minor and
            // clobbering each other's write.
            $locked = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            $payment = Payment::create([
                'account_id' => $locked->account_id,
                'amount_minor' => $amount->getMinorAmount()->toInt(),
                'currency' => $amount->getCurrency()->getCurrencyCode(),
                'reference' => $reference,
            ]);

            PaymentAllocation::create([
                'payment_id' => $payment->id,
                'invoice_id' => $locked->id,
                'amount_minor' => $amount->getMinorAmount()->toInt(),
            ]);

            $paid = $locked->paid_minor + $amount->getMinorAmount()->toInt();
            $fullyPaid = $paid >= $locked->total_minor;
            $locked->forceFill([
                'paid_minor' => $paid,
                'state' => $fullyPaid ? InvoiceState::Paid : InvoiceState::PartiallyPaid,
                'paid_at' => $fullyPaid ? now() : $locked->paid_at,
            ])->save();

            // A fully paid invoice settles the charges it billed. A partial
            // payment leaves them invoiced.
            if ($fullyPaid) {
                $chargeIds = $locked->lines()->whereNotNull('charge_id')->pluck('charge_id')->unique();
                foreach (Charge::whereIn('id', $chargeIds)->get() as $charge) {
                    $charge->markSettled();
                }
            }

            // Reflect the committed state onto the caller's model so the event
            // dispatch below and the returned invoice see the new paid state.
            $invoice->setRawAttributes($locked->getAttributes(), true);

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
