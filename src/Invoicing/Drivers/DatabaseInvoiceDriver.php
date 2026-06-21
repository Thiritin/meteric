<?php

declare(strict_types=1);

namespace Meteric\Invoicing\Drivers;

use Brick\Money\Money;
use Illuminate\Support\Facades\DB;
use Meteric\Contracts\InvoiceDriver;
use Meteric\Contracts\TaxResolver;
use Meteric\Enums\CreditState;
use Meteric\Enums\InvoiceState;
use Meteric\Invoicing\CreditNoteDraft;
use Meteric\Invoicing\InvoiceDraft;
use Meteric\Invoicing\IssuedCreditNote;
use Meteric\Invoicing\IssuedInvoice;
use Meteric\Models\CreditNote;
use Meteric\Models\Invoice;
use Meteric\Models\InvoiceLine;

/**
 * Canonical local invoice sink. Always available — the default driver. Builds
 * an immutable invoice + lines from the draft's charges, applies tax, and
 * returns an IssuedInvoice. Throws on any failure so charges stay `pending`.
 */
final class DatabaseInvoiceDriver implements InvoiceDriver
{
    public function __construct(private TaxResolver $tax) {}

    public function issue(InvoiceDraft $draft): IssuedInvoice
    {
        return DB::transaction(function () use ($draft): IssuedInvoice {
            $taxContext = $draft->account->taxContext();

            $invoice = Invoice::create([
                'account_id' => $draft->account->id,
                'customer_type' => $draft->account->owner_type,
                'customer_id' => $draft->account->owner_id,
                'driver' => 'database',
                'state' => InvoiceState::Draft,
                'currency' => $draft->currency,
                'idempotency_key' => $draft->idempotencyKey,
            ]);

            $subtotal = Money::ofMinor(0, $draft->currency);
            $taxTotal = Money::ofMinor(0, $draft->currency);
            $sort = 0;

            foreach ($draft->charges as $charge) {
                $net = $charge->money();
                $taxResult = $this->tax->resolve($net, $taxContext);

                InvoiceLine::create([
                    'invoice_id' => $invoice->id,
                    'charge_id' => $charge->id,
                    'kind' => $charge->kind,
                    'title' => $charge->title,
                    'group' => $charge->group,
                    'description' => $charge->description,
                    'quantity' => $charge->quantity,
                    'unit' => $charge->unit,
                    'unit_minor' => $charge->unit_minor,
                    'unit_rate' => $charge->unit_rate,
                    'amount_minor' => $charge->amount_minor,
                    'tax_rate' => $taxResult->rate,
                    'tax_minor' => $taxResult->amount->getMinorAmount()->toInt(),
                    'tax_label' => $taxResult->label,
                    'currency' => $draft->currency,
                    'covers' => $charge->covers,
                    'dimension_id' => $charge->dimension_id,
                    'metadata' => $charge->metadata,
                    'sort' => $sort++,
                ]);

                $subtotal = $subtotal->plus($net);
                $taxTotal = $taxTotal->plus($taxResult->amount);
            }

            $total = $subtotal->plus($taxTotal);

            // Financials are set while still draft, then frozen by flipping to open.
            $invoice->forceFill([
                'subtotal_minor' => $subtotal->getMinorAmount()->toInt(),
                'tax_minor' => $taxTotal->getMinorAmount()->toInt(),
                'total_minor' => $total->getMinorAmount()->toInt(),
                'number' => $this->nextNumber(),
                'state' => InvoiceState::Open,
                'issued_at' => now(),
            ])->save();

            return new IssuedInvoice(
                invoiceId: $invoice->id,
                number: $invoice->number,
            );
        });
    }

    public function void(IssuedInvoice $invoice): void
    {
        Invoice::whereKey($invoice->invoiceId)->update(['state' => InvoiceState::Void]);
    }

    public function creditNote(IssuedInvoice $invoice, CreditNoteDraft $draft): IssuedCreditNote
    {
        $model = Invoice::findOrFail($invoice->invoiceId);

        // Credit the given net amount at the invoice's tax rate, so the note
        // reverses the same VAT the invoice charged.
        $rate = (float) ($model->lines->max('tax_rate') ?? 0);
        $net = $draft->amount->getMinorAmount()->toInt();

        $note = CreditNote::create([
            'invoice_id' => $model->id,
            'driver' => 'database',
            'state' => CreditState::Issued,
            'reason' => $draft->reason,
            'amount_minor' => $net,
            'tax_minor' => (int) round($net * $rate),
            'currency' => $draft->amount->getCurrency()->getCurrencyCode(),
            'number' => $this->nextNumber('CN'),
            'issued_at' => now(),
        ]);

        return new IssuedCreditNote(creditNoteId: $note->id, number: $note->number);
    }

    private function nextNumber(string $prefix = 'INV'): string
    {
        $year = now()->year;
        $seq = (int) DB::table('meteric_invoices')->whereYear('created_at', $year)->count() + 1;

        return sprintf('%s-%d-%06d', $prefix, $year, $seq);
    }
}
