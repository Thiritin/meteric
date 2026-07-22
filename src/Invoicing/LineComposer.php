<?php

declare(strict_types=1);

namespace Meteric\Invoicing;

use Brick\Money\Money;
use Illuminate\Support\Collection;
use Meteric\Contracts\TaxResolver;
use Meteric\Enums\InvoiceState;
use Meteric\Models\Charge;
use Meteric\Models\Invoice;
use Meteric\Models\InvoiceLine;
use Meteric\Support\Models;
use Meteric\Tax\TaxContext;
use Meteric\Tax\TaxResult;

/**
 * Assembles a draft invoice's document lines from charges, applying per-line
 * tax. Shared by every driver (the local invoice is always the source of
 * truth) and by manual draft editing on the entry class.
 */
final class LineComposer
{
    public function __construct(private TaxResolver $tax) {}

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
    public function rebuild(Invoice $invoice, Collection $charges): void
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

    /** Resolve tax on a net amount in the invoice account's tax context. */
    public function resolveTax(Invoice $invoice, Money $net): TaxResult
    {
        return $this->tax->resolve($net, $invoice->account->taxContext());
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
}
