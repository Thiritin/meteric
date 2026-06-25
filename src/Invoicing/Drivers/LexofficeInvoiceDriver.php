<?php

declare(strict_types=1);

namespace Meteric\Invoicing\Drivers;

use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use LogicException;
use Meteric\Contracts\InvoiceDriver;
use Meteric\Invoicing\CreditNoteDraft;
use Meteric\Invoicing\InvoiceDraft;
use Meteric\Invoicing\IssuedCreditNote;
use Meteric\Invoicing\IssuedInvoice;
use Meteric\Models\CreditNote;
use Meteric\Models\Invoice;
use Meteric\Models\InvoiceLine;
use RuntimeException;

/**
 * Lexware Office (formerly lexoffice) invoice driver. Composes the
 * DatabaseInvoiceDriver for canonical local persistence, then pushes the
 * finalized document to the Lexware Office REST API (api.lexoffice.io / v1).
 *
 * Failure boundary, read carefully:
 * the local invoice is the source of truth. issue() first persists it via the
 * composed local driver (which flips charges to invoiced in its own logic via
 * the caller), then POSTs to lexoffice. If the POST fails AFTER the local
 * issue, we do NOT roll back the local invoice: it already exists and is
 * authoritative. We re-throw so the caller learns the downstream sync failed.
 * The charge-vs-invoice guarantee holds because the local invoice stands; only
 * the external mirror is missing, and that can be re-synced. We never delete a
 * local invoice to chase a remote outage.
 */
final class LexofficeInvoiceDriver implements InvoiceDriver
{
    public function __construct(
        private DatabaseInvoiceDriver $local,
        private string $apiToken,
        private string $baseUrl = 'https://api.lexoffice.io',
        private string $taxType = 'net',
        private string $defaultCountry = 'DE',
    ) {}

    public function issue(InvoiceDraft $draft): IssuedInvoice
    {
        $issued = $this->local->issue($draft);

        /** @var Invoice $invoice */
        $invoice = Invoice::with('lines', 'account')->findOrFail($issued->invoiceId);

        $body = $this->invoiceBody($invoice);

        $response = $this->client()->post('/v1/invoices?finalize=true', $body);
        $data = $this->ok($response, 'invoice');

        $externalId = (string) $data['id'];
        $externalUrl = (string) ($data['resourceUri'] ?? '');

        $invoice->forceFill([
            'external_id' => $externalId,
            'external_url' => $externalUrl,
        ])->save();

        return new IssuedInvoice(
            invoiceId: $invoice->id,
            number: $invoice->number,
            externalId: $externalId,
            externalUrl: $externalUrl,
        );
    }

    /**
     * Finalize an existing Draft invoice: POST its current lines to lexoffice and
     * record the external id/url + number, then flip it to open. Refuses an
     * invoice already sent (it carries an external id). Sends the lines as they
     * stand, no rebuild from charges.
     */
    public function finalize(Invoice $invoice): IssuedInvoice
    {
        if ($invoice->external_id !== null) {
            throw new LogicException('Invoice has already been sent to Lexware Office.');
        }

        $invoice->loadMissing('lines', 'account');

        // Assign the number, flip to open, and stamp issued_at via the local
        // driver, then POST the current lines. The voucherDate uses issued_at.
        $this->local->finalize($invoice);

        $body = $this->invoiceBody($invoice);

        $response = $this->client()->post('/v1/invoices?finalize=true', $body);
        $data = $this->ok($response, 'invoice');

        $externalId = (string) $data['id'];
        $externalUrl = (string) ($data['resourceUri'] ?? '');

        $invoice->forceFill([
            'external_id' => $externalId,
            'external_url' => $externalUrl,
        ])->save();

        return new IssuedInvoice(
            invoiceId: $invoice->id,
            number: $invoice->number,
            externalId: $externalId,
            externalUrl: $externalUrl,
        );
    }

    public function creditNote(IssuedInvoice $invoice, CreditNoteDraft $draft): IssuedCreditNote
    {
        $result = $this->local->creditNote($invoice, $draft);

        /** @var CreditNote $note */
        $note = CreditNote::with('invoice.account')->findOrFail($result->creditNoteId);

        $body = $this->creditNoteBody($note, $draft);

        $response = $this->client()->post('/v1/credit-notes?finalize=true', $body);
        $data = $this->ok($response, 'credit-note');

        $externalId = (string) $data['id'];

        $note->forceFill(['external_id' => $externalId])->save();

        return new IssuedCreditNote(
            creditNoteId: $note->id,
            number: $note->number,
            externalId: $externalId,
        );
    }

    /**
     * Lexware Office does not allow deleting or voiding a finalized invoice: the
     * documented correction path is a credit note. We therefore refuse to void a
     * finalized lexoffice invoice and point the caller at creditNote(). A local
     * draft that never reached lexoffice (no external id) is voided locally.
     */
    public function void(IssuedInvoice $invoice): void
    {
        $model = Invoice::find($invoice->invoiceId);

        if ($model !== null && $model->external_id === null) {
            $this->local->void($invoice);

            return;
        }

        throw new LogicException(
            'Lexware Office does not permit voiding a finalized invoice. '.
            'Issue a credit note to correct or reverse it.'
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function invoiceBody(Invoice $invoice): array
    {
        $profile = $invoice->account?->tax_profile ?? [];
        $date = ($invoice->issued_at ?? Carbon::now())->format('Y-m-d\TH:i:s.vP');

        return [
            'voucherDate' => $date,
            'address' => [
                'name' => (string) ($profile['name'] ?? 'Customer'),
                'countryCode' => (string) ($profile['country'] ?? $this->defaultCountry),
            ],
            'lineItems' => $this->lineItems($invoice),
            'totalPrice' => [
                'currency' => $invoice->currency,
            ],
            'taxConditions' => [
                'taxType' => $this->taxType,
            ],
            'shippingConditions' => $this->shippingConditions($invoice, $date),
        ];
    }

    /**
     * Build the lexoffice line items. The sub-line hierarchy is flattened: each
     * parent line emits as a custom item, then its children follow as their own
     * custom items with an indented "- {title}" name, each carrying its own net +
     * tax. The net of every emitted line sums to the invoice subtotal. Parent
     * lines that carry a `group` are clustered into sections, each preceded by a
     * `type:text` heading; ungrouped lines lead in their original order.
     *
     * @return list<array<string,mixed>>
     */
    private function lineItems(Invoice $invoice): array
    {
        $all = $invoice->lines->sortBy('sort')->values();
        $children = $all->filter(fn (InvoiceLine $l): bool => $l->parent_id !== null)->groupBy('parent_id');
        $parents = $all->filter(fn (InvoiceLine $l): bool => $l->parent_id === null)->values();

        $ungrouped = [];
        /** @var array<string,list<array<string,mixed>>> $groups */
        $groups = [];
        foreach ($parents as $parent) {
            $bucket = ($parent->group === null || $parent->group === '') ? null : $parent->group;

            $emit = function (array $item) use (&$ungrouped, &$groups, $bucket): void {
                if ($bucket === null) {
                    $ungrouped[] = $item;
                } else {
                    $groups[$bucket][] = $item;
                }
            };

            $emit($this->lineItem($parent, $invoice->currency));
            foreach ($children->get($parent->id, collect())->sortBy('sort') as $child) {
                $emit($this->lineItem($child, $invoice->currency, indent: true));
            }
        }

        $out = $ungrouped;
        foreach ($groups as $name => $items) {
            $out[] = ['type' => 'text', 'name' => $name, 'description' => ''];
            foreach ($items as $item) {
                $out[] = $item;
            }
        }

        return $out;
    }

    /**
     * The service timeframe lexoffice shows on the invoice. Spans the billed
     * periods (earliest line start to latest line end) as a `serviceperiod`;
     * falls back to a single-date `service` when no line carries a period.
     *
     * @return array<string,string>
     */
    private function shippingConditions(Invoice $invoice, string $fallbackDate): array
    {
        $periods = $invoice->lines->map(fn (InvoiceLine $line) => $line->covers)->filter()->values();

        if ($periods->isEmpty()) {
            return ['shippingDate' => $fallbackDate, 'shippingType' => 'service'];
        }

        $start = $periods->map(fn ($p) => $p->start)->min();
        $end = $periods->map(fn ($p) => $p->inclusiveEnd())->max();   // last moment of service, not the next period's start

        return [
            'shippingDate' => $start->format('Y-m-d\TH:i:s.vP'),
            'shippingEndDate' => $end->format('Y-m-d\TH:i:s.vP'),
            'shippingType' => 'serviceperiod',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function lineItem(InvoiceLine $line, string $currency, bool $indent = false): array
    {
        $name = (string) ($line->title ?? $line->description ?? '');

        return [
            'type' => 'custom',
            'name' => $indent ? '- '.$name : $name,
            'description' => (string) ($line->description ?? ''),
            'quantity' => (float) $line->quantity,
            'unitName' => $line->unit,
            'unitPrice' => [
                'currency' => $currency,
                'netAmount' => $this->minorToDecimal($line->amount_minor, $currency),
                'taxRatePercentage' => (int) round($line->tax_rate * 100),
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function creditNoteBody(CreditNote $note, CreditNoteDraft $draft): array
    {
        $invoice = $note->invoice;
        $profile = $invoice?->account?->tax_profile ?? [];
        $date = ($note->issued_at ?? Carbon::now())->format('Y-m-d\TH:i:s.vP');
        $currency = $note->currency;

        // Mirror the credited invoice's tax rate so the credit note reverses
        // the same VAT it charged. The credited amount is net.
        $taxRate = (float) ($invoice?->lines->max('tax_rate') ?? 0);

        return [
            'voucherDate' => $date,
            'address' => [
                'name' => (string) ($profile['name'] ?? 'Customer'),
                'countryCode' => (string) ($profile['country'] ?? $this->defaultCountry),
            ],
            'lineItems' => [[
                'type' => 'custom',
                'name' => $draft->reason ?? 'Credit note',
                'description' => $draft->reason ?? '',
                'quantity' => 1.0,
                'unitName' => 'Korrektur',   // Lexware requires a non-blank unit
                'unitPrice' => [
                    'currency' => $currency,
                    'netAmount' => $this->minorToDecimal($note->amount_minor, $currency),
                    'taxRatePercentage' => (int) round($taxRate * 100),
                ],
            ]],
            'totalPrice' => [
                'currency' => $currency,
            ],
            'taxConditions' => [
                'taxType' => $this->taxType,
            ],
        ];
    }

    /**
     * Integer minor units to a 2-decimal major-unit float, no float drift on
     * the stored value (brick/money does the division exactly, then we render).
     */
    private function minorToDecimal(int $minor, string $currency): float
    {
        $money = Money::ofMinor($minor, $currency);

        return (float) $money->getAmount()->toScale(2, RoundingMode::HALF_UP)->__toString();
    }

    private function client(): PendingRequest
    {
        return Http::withToken($this->apiToken)
            ->acceptJson()
            ->baseUrl($this->baseUrl);
    }

    /**
     * @return array<string,mixed>
     */
    private function ok(Response $response, string $what): array
    {
        if ($response->failed()) {
            throw new RuntimeException(sprintf(
                'Lexware Office %s request failed (HTTP %d): %s',
                $what,
                $response->status(),
                $response->body(),
            ));
        }

        /** @var array<string,mixed> $json */
        $json = $response->json();

        return $json;
    }
}
