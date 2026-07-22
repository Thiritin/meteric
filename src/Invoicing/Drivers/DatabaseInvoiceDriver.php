<?php

declare(strict_types=1);

namespace Meteric\Invoicing\Drivers;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Meteric\Contracts\Clock;
use Meteric\Contracts\InvoiceDriver;
use Meteric\Contracts\TaxResolver;
use Meteric\Enums\CreditState;
use Meteric\Enums\InvoiceState;
use Meteric\Invoicing\CreditNoteDraft;
use Meteric\Invoicing\InvoiceDraft;
use Meteric\Invoicing\IssuedCreditNote;
use Meteric\Invoicing\IssuedInvoice;
use Meteric\Models\Charge;
use Meteric\Models\CreditNote;
use Meteric\Models\Invoice;
use Meteric\Models\InvoiceLine;
use Meteric\Support\Models;
use Meteric\Support\Pg;
use Meteric\Tax\TaxContext;
use Meteric\Tax\TaxResult;

/**
 * Canonical local invoice sink. Always available — the default driver. Builds
 * an immutable invoice + lines from the draft's charges, applies tax, and
 * returns an IssuedInvoice. Throws on any failure so charges stay `pending`.
 */
final class DatabaseInvoiceDriver implements InvoiceDriver
{
    public function __construct(private TaxResolver $tax, private Clock $clock) {}

    private function now(): CarbonImmutable
    {
        return $this->clock->now();
    }

    public function issue(InvoiceDraft $draft): IssuedInvoice
    {
        return DB::transaction(function () use ($draft): IssuedInvoice {
            $invoice = Models::query(Invoice::class)->create([
                'account_id' => $draft->account->id,
                'customer_type' => $draft->account->owner_type,
                'customer_id' => $draft->account->owner_id,
                'driver' => 'database',
                'state' => InvoiceState::Draft,
                'currency' => $draft->currency,
                'idempotency_key' => $draft->idempotencyKey,
            ]);

            // The draft carries the charges to bill; rebuildLines reads them,
            // builds the lines, and flips each charge to invoiced.
            $invoice->setRelation('pendingCharges', $draft->charges->values());
            $this->rebuildLines($invoice, $draft->charges->values());

            // Financials are set while still draft, then frozen by flipping to
            // open. due_at is stamped here so a driver that commits locally then
            // fails a downstream sync still leaves a dunnable invoice.
            $issuedAt = $this->now();
            $invoice->forceFill([
                'number' => $this->nextNumber(),
                'state' => InvoiceState::Open,
                'issued_at' => $issuedAt,
                'due_at' => $issuedAt->copy()->addDays($draft->dueDays),
            ])->save();

            return new IssuedInvoice(
                invoiceId: $invoice->id,
                number: $invoice->number,
            );
        });
    }

    /**
     * Rebuild a Draft invoice's lines from a set of charges. Deletes the existing
     * lines, then writes one document line per charge: charges sharing a non-null
     * line_group fold into a parent product line with its options/addons as nested
     * sub-lines (parent_id). Each line carries its own net + tax; the invoice
     * totals sum every line. Flips each billed charge to invoiced. Only valid
     * while the invoice is Draft (a trigger freezes issued lines).
     *
     * @param  Collection<int,Charge>  $charges
     */
    public function rebuildLines(Invoice $invoice, Collection $charges): void
    {
        if ($invoice->state !== InvoiceState::Draft) {
            throw new \LogicException('Cannot rebuild lines of a non-draft invoice.');
        }

        $taxContext = $invoice->account->taxContext();
        $currency = $invoice->currency;

        $invoice->lines()->delete();

        $subtotal = Money::ofMinor(0, $currency);
        $taxTotal = Money::ofMinor(0, $currency);
        $sort = 0;

        foreach ($this->lineGroups($charges) as $group) {
            $base = $this->baseCharge($group);

            $parent = $this->writeLine($invoice, $base, $taxContext, $currency, null, $sort);
            $sort += 100;
            $subtotal = $subtotal->plus($parent->amount);
            $taxTotal = $taxTotal->plus(Money::ofMinor($parent->tax_minor, $currency));

            $childSort = $sort - 99;
            foreach ($group as $charge) {
                if ($charge->id === $base->id) {
                    continue;
                }
                $child = $this->writeLine($invoice, $charge, $taxContext, $currency, $parent->id, $childSort++);
                $subtotal = $subtotal->plus($child->amount);
                $taxTotal = $taxTotal->plus(Money::ofMinor($child->tax_minor, $currency));
            }
        }

        $total = $subtotal->plus($taxTotal);

        $invoice->forceFill([
            'subtotal_minor' => $subtotal->getMinorAmount()->toInt(),
            'tax_minor' => $taxTotal->getMinorAmount()->toInt(),
            'total_minor' => $total->getMinorAmount()->toInt(),
        ])->save();
    }

    /**
     * Write one invoice line from a charge (its own net + per-line tax) and flip
     * the charge to invoiced. $parentId nests it as a sub-line.
     */
    private function writeLine(Invoice $invoice, Charge $charge, TaxContext $taxContext, string $currency, ?string $parentId, int $sort): InvoiceLine
    {
        $net = $charge->money();
        $taxResult = $this->tax->resolve($net, $taxContext);

        $line = Models::query(InvoiceLine::class)->create([
            'invoice_id' => $invoice->id,
            'charge_id' => $charge->id,
            'parent_id' => $parentId,
            'kind' => $charge->kind,
            'title' => $charge->title,
            'group' => $charge->group,
            'line_group' => $charge->line_group,
            'description' => $charge->description,
            'quantity' => $charge->quantity,
            'unit' => $charge->unit,
            'unit_minor' => $charge->unit_minor,
            'unit_rate' => $charge->unit_rate,
            'amount_minor' => $net->getMinorAmount()->toInt(),
            'tax_rate' => $taxResult->rate,
            'tax_minor' => $taxResult->amount->getMinorAmount()->toInt(),
            'tax_label' => $taxResult->label,
            'currency' => $currency,
            'covers' => $charge->covers,
            'dimension_id' => $charge->dimension_id,
            'metadata' => $charge->metadata,
            'sort' => $sort,
        ]);

        $charge->markInvoiced();

        return $line;
    }

    /**
     * Finalize a Draft invoice: assign a number when missing, flip to open, and
     * stamp issued_at. Sends the invoice's current lines as-is (no rebuild).
     */
    public function finalize(Invoice $invoice): IssuedInvoice
    {
        $invoice->forceFill([
            'number' => $invoice->number ?? $this->nextNumber(),
            'state' => InvoiceState::Open,
            'issued_at' => $invoice->issued_at ?? $this->now(),
        ])->save();

        return new IssuedInvoice(
            invoiceId: $invoice->id,
            number: $invoice->number,
            externalId: $invoice->external_id,
            externalUrl: $invoice->external_url,
        );
    }

    /**
     * Split the charges into line groups. Charges sharing a non-null line_group
     * fold into one group (a product with its options/addons => a parent line
     * with sub-lines); charges with a null line_group each stay their own group
     * (a standalone parent, no children). Order is preserved.
     *
     * @param  Collection<int,Charge>  $charges
     * @return list<Collection<int,Charge>>
     */
    private function lineGroups(Collection $charges): array
    {
        /** @var list<Collection<int,Charge>> $groups */
        $groups = [];
        /** @var array<string,int> $index */
        $index = [];
        foreach ($charges as $charge) {
            $key = $charge->line_group;
            if ($key === null || $key === '') {
                $groups[] = collect([$charge]);

                continue;
            }
            if (! isset($index[$key])) {
                $index[$key] = count($groups);
                $groups[] = collect();
            }
            $groups[$index[$key]]->push($charge);
        }

        return $groups;
    }

    /**
     * The parent charge of a group: the base-line kind (LineKind::isBaseLine),
     * or the first charge when none qualifies.
     *
     * @param  Collection<int,Charge>  $group
     */
    private function baseCharge(Collection $group): Charge
    {
        return $group->first(fn (Charge $c): bool => $c->kind->isBaseLine())
            ?? $group->first();
    }

    /** Resolve tax on a net amount in the invoice account's tax context. */
    public function resolveTax(Invoice $invoice, Money $net): TaxResult
    {
        return $this->tax->resolve($net, $invoice->account->taxContext());
    }

    public function void(IssuedInvoice $invoice): void
    {
        Models::query(Invoice::class)->whereKey($invoice->invoiceId)->update(['state' => InvoiceState::Void]);
    }

    public function creditNote(IssuedInvoice $invoice, CreditNoteDraft $draft): IssuedCreditNote
    {
        $model = Models::query(Invoice::class)->findOrFail($invoice->invoiceId);

        // Credit tax at the invoice's blended effective rate (tax / subtotal),
        // computed in BigDecimal. A full credit reverses exactly the VAT charged;
        // a partial credit reverses it proportionally. Using max(tax_rate) would
        // over-credit VAT on a mixed-rate invoice (e.g. reverse-charge + domestic).
        $net = $draft->amount->getMinorAmount()->toInt();
        $subtotal = (int) $model->subtotal_minor;
        $taxTotal = (int) $model->tax_minor;
        $creditTax = $subtotal > 0
            ? BigDecimal::of($net)->multipliedBy($taxTotal)->dividedBy($subtotal, 0, RoundingMode::HALF_UP)->toInt()
            : 0;

        $note = Models::query(CreditNote::class)->create([
            'invoice_id' => $model->id,
            'driver' => 'database',
            'state' => CreditState::Issued,
            'reason' => $draft->reason,
            'amount_minor' => $net,
            'tax_minor' => $creditTax,
            'currency' => $draft->amount->getCurrency()->getCurrencyCode(),
            'number' => $this->nextNumber('CN'),
            'issued_at' => $this->now(),
        ]);

        return new IssuedCreditNote(creditNoteId: $note->id, number: $note->number);
    }

    /**
     * Gapless per-prefix, per-year document number. An atomic upsert on the
     * counter row (INSERT ... ON CONFLICT DO UPDATE ... RETURNING) takes a row
     * lock, so concurrent issues serialize instead of colliding on the number.
     * Runs inside the caller's transaction, so a rolled-back issue does not
     * consume a number. Counts only assigned numbers, never drafts or voids.
     */
    private function nextNumber(string $prefix = 'INV'): string
    {
        $year = (int) $this->now()->year;
        $table = Pg::table('invoice_numbers');

        $row = DB::selectOne(
            "INSERT INTO {$table} (prefix, year, seq) VALUES (?, ?, 1)
             ON CONFLICT (prefix, year) DO UPDATE SET seq = {$table}.seq + 1
             RETURNING seq",
            [$prefix, $year],
        );

        return sprintf('%s-%d-%06d', $prefix, $year, (int) $row->seq);
    }
}
