<?php

declare(strict_types=1);

use Brick\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Meteric\Contracts\TaxResolver;
use Meteric\Enums\ChargeState;
use Meteric\Enums\InvoiceState;
use Meteric\Enums\LineKind;
use Meteric\Invoicing\CreditNoteDraft;
use Meteric\Invoicing\Drivers\DatabaseInvoiceDriver;
use Meteric\Invoicing\Drivers\LexofficeInvoiceDriver;
use Meteric\Invoicing\InvoiceDraft;
use Meteric\Invoicing\IssuedInvoice;
use Meteric\Models\BillingAccount;
use Meteric\Models\Charge;
use Meteric\Models\CreditNote;
use Meteric\Models\Invoice;
use Meteric\Support\Period;

uses(RefreshDatabase::class);

function lexAccount(): BillingAccount
{
    return BillingAccount::create([
        'owner_type' => 'user', 'owner_id' => '1', 'currency' => 'EUR',
        'tax_profile' => ['country' => 'DE', 'merchant_country' => 'DE', 'name' => 'Bike & Ride GmbH'],
    ]);
}

function lexCharge(BillingAccount $account, int $amountMinor, string $title, string $desc): Charge
{
    return Charge::create([
        'account_id' => $account->id,
        'origin_type' => 'manual', 'origin_id' => (string) Str::uuid(),
        'kind' => LineKind::Recurring, 'billing_mode' => 'in_advance',
        'state' => ChargeState::Pending, 'title' => $title, 'description' => $desc,
        'quantity' => 1, 'unit' => 'Stück', 'unit_minor' => $amountMinor, 'amount_minor' => $amountMinor,
        'currency' => 'EUR', 'idempotency_key' => (string) Str::uuid(),
    ]);
}

function lexDriver(): LexofficeInvoiceDriver
{
    return new LexofficeInvoiceDriver(
        local: app(DatabaseInvoiceDriver::class),
        apiToken: 'test-token',
        baseUrl: 'https://api.lexoffice.io',
        taxType: 'net',
        defaultCountry: 'DE',
    );
}

function lexDraft(BillingAccount $account): InvoiceDraft
{
    $charges = Charge::where('account_id', $account->id)
        ->where('state', ChargeState::Pending->value)
        ->get();

    return new InvoiceDraft(
        account: $account,
        currency: 'EUR',
        charges: $charges,
        idempotencyKey: (string) Str::uuid(),
    );
}

function lexInvoiceResponse(string $id = 'e9066f04-8cc7-4616-93f8-ac9ecc8479c8'): array
{
    return [
        'id' => $id,
        'resourceUri' => "https://api.lexoffice.io/v1/invoices/{$id}",
        'createdDate' => '2023-06-17T18:32:07.480+02:00',
        'updatedDate' => '2023-06-17T18:32:07.551+02:00',
        'version' => 1,
    ];
}

beforeEach(function () {
    app()->bind(DatabaseInvoiceDriver::class, fn ($app) => new DatabaseInvoiceDriver(
        $app->make(TaxResolver::class)
    ));
});

it('issues an invoice through the lexoffice driver and stores the external id', function () {
    Http::fake([
        'api.lexoffice.io/v1/invoices*' => Http::response(lexInvoiceResponse(), 201),
    ]);

    $account = lexAccount();
    lexCharge($account, 1560, 'VPS XL', "Hosting plan\nFrankfurt region");

    $draft = lexDraft($account);
    $issued = lexDriver()->issue($draft);

    expect($issued)->toBeInstanceOf(IssuedInvoice::class)
        ->and($issued->externalId)->toBe('e9066f04-8cc7-4616-93f8-ac9ecc8479c8')
        ->and($issued->externalUrl)->toBe('https://api.lexoffice.io/v1/invoices/e9066f04-8cc7-4616-93f8-ac9ecc8479c8');

    $invoice = Invoice::findOrFail($issued->invoiceId);
    expect($invoice->external_id)->toBe('e9066f04-8cc7-4616-93f8-ac9ecc8479c8')
        ->and($invoice->external_url)->toBe('https://api.lexoffice.io/v1/invoices/e9066f04-8cc7-4616-93f8-ac9ecc8479c8');

    Http::assertSent(function ($request) {
        $body = $request->data();

        return $request->method() === 'POST'
            && $request->url() === 'https://api.lexoffice.io/v1/invoices?finalize=true'
            && $request->hasHeader('Authorization', 'Bearer test-token')
            && $body['taxConditions']['taxType'] === 'net'
            && $body['shippingConditions']['shippingType'] === 'service'
            && $body['address']['name'] === 'Bike & Ride GmbH'
            && $body['address']['countryCode'] === 'DE'
            && $body['lineItems'][0]['type'] === 'custom'
            && $body['lineItems'][0]['name'] === 'VPS XL'
            && $body['lineItems'][0]['description'] === "Hosting plan\nFrankfurt region"
            && $body['lineItems'][0]['quantity'] === 1.0
            && $body['lineItems'][0]['unitName'] === 'Stück';
    });
});

it('groups lines under text separators and spans a service period', function () {
    Http::fake([
        'api.lexoffice.io/v1/invoices*' => Http::response(lexInvoiceResponse(), 201),
    ]);

    $account = lexAccount();
    $covers = new Period(CarbonImmutable::parse('2026-06-01Z'), CarbonImmutable::parse('2026-07-01Z'));
    foreach ([['Domains', 'example.com', 1200], ['Servers', 'VPS XL - h1', 1000], ['Servers', 'VPS XL - h2', 1000]] as [$group, $title, $minor]) {
        Charge::create([
            'account_id' => $account->id, 'origin_type' => 'manual', 'origin_id' => (string) Str::uuid(),
            'kind' => LineKind::Recurring, 'billing_mode' => 'in_advance', 'state' => ChargeState::Pending,
            'title' => $title, 'group' => $group, 'description' => '2026-06-01 to 2026-07-01',
            'quantity' => 1, 'unit' => 'month', 'unit_minor' => $minor, 'amount_minor' => $minor,
            'currency' => 'EUR', 'covers' => $covers, 'idempotency_key' => (string) Str::uuid(),
        ]);
    }

    lexDriver()->issue(lexDraft($account));

    Http::assertSent(function ($request) {
        $body = $request->data();
        $items = $body['lineItems'];
        $names = array_column($items, 'name');
        $texts = array_values(array_filter($items, fn ($i) => $i['type'] === 'text'));

        // One text separator per group, the group's lines following it.
        return count($texts) === 2
            && $texts[0]['name'] === 'Domains'
            && $texts[1]['name'] === 'Servers'
            && in_array('example.com', $names, true)
            && in_array('VPS XL - h1', $names, true)
            // The service period spans the billed range.
            && $body['shippingConditions']['shippingType'] === 'serviceperiod'
            && str_starts_with($body['shippingConditions']['shippingDate'], '2026-06-01')
            && str_starts_with($body['shippingConditions']['shippingEndDate'], '2026-06-30');
    });
});

it('maps a EUR 15.60 net line at 19 percent tax to lexoffice decimals', function () {
    Http::fake([
        'api.lexoffice.io/v1/invoices*' => Http::response(lexInvoiceResponse(), 201),
    ]);

    $account = lexAccount();
    lexCharge($account, 1560, 'VPS XL', 'Hosting');

    lexDriver()->issue(lexDraft($account));

    Http::assertSent(function ($request) {
        $price = $request->data()['lineItems'][0]['unitPrice'];

        return $price['currency'] === 'EUR'
            && $price['netAmount'] === 15.6
            && $price['taxRatePercentage'] === 19;
    });
});

it('issues a credit note through the lexoffice driver and stores the returned id', function () {
    Http::fake([
        'api.lexoffice.io/v1/invoices*' => Http::response(lexInvoiceResponse(), 201),
        'api.lexoffice.io/v1/credit-notes*' => Http::response(
            lexInvoiceResponse('aa111111-2222-3333-4444-555566667777') +
            ['resourceUri' => 'https://api.lexoffice.io/v1/credit-notes/aa111111-2222-3333-4444-555566667777'],
            201
        ),
    ]);

    $account = lexAccount();
    lexCharge($account, 1560, 'VPS XL', 'Hosting');

    $driver = lexDriver();
    $issued = $driver->issue(lexDraft($account));

    $creditDraft = new CreditNoteDraft(
        amount: Money::ofMinor(1560, 'EUR'),
        reason: 'Customer refund',
    );

    $result = $driver->creditNote($issued, $creditDraft);

    expect($result->externalId)->toBe('aa111111-2222-3333-4444-555566667777');

    $note = CreditNote::findOrFail($result->creditNoteId);
    expect($note->external_id)->toBe('aa111111-2222-3333-4444-555566667777');

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/v1/credit-notes')) {
            return false;
        }
        $body = $request->data();

        return $request->url() === 'https://api.lexoffice.io/v1/credit-notes?finalize=true'
            && $request->hasHeader('Authorization', 'Bearer test-token')
            && $body['lineItems'][0]['name'] === 'Customer refund'
            && $body['lineItems'][0]['unitPrice']['netAmount'] === 15.6
            && $body['lineItems'][0]['unitPrice']['taxRatePercentage'] === 19  // mirrors the invoice VAT
            && $body['taxConditions']['taxType'] === 'net';
    });
});

it('throws on a failed lexoffice response while keeping the local invoice', function () {
    Http::fake([
        'api.lexoffice.io/v1/invoices*' => Http::response([
            'IssueList' => [[
                'i18nKey' => 'invalid_value',
                'source' => 'lineItems[0].unitPrice',
                'type' => 'validation_failure',
            ]],
        ], 406),
    ]);

    $account = lexAccount();
    lexCharge($account, 1560, 'VPS XL', 'Hosting');

    expect(fn () => lexDriver()->issue(lexDraft($account)))
        ->toThrow(RuntimeException::class);

    // Local invoice is the source of truth: it still exists, with no external id.
    $invoice = Invoice::firstOrFail();
    expect($invoice->external_id)->toBeNull()
        ->and($invoice->number)->not->toBeNull();
});

it('finalizes a draft invoice by posting its current lines to lexoffice', function () {
    Http::fake([
        'api.lexoffice.io/v1/invoices*' => Http::response(lexInvoiceResponse(), 201),
    ]);

    $account = lexAccount();
    lexCharge($account, 1560, 'VPS XL', 'Hosting');

    // Build a draft directly (no document sent yet) with its lines.
    $invoice = Invoice::create([
        'account_id' => $account->id, 'customer_type' => 'user', 'customer_id' => '1',
        'driver' => 'lexoffice', 'state' => InvoiceState::Draft, 'currency' => 'EUR',
    ]);
    $charges = Charge::where('account_id', $account->id)->get();
    app(DatabaseInvoiceDriver::class)->rebuildLines($invoice, $charges);

    $issued = lexDriver()->finalize($invoice->fresh());

    expect($issued->externalId)->toBe('e9066f04-8cc7-4616-93f8-ac9ecc8479c8');

    $fresh = $invoice->fresh();
    expect($fresh->state)->toBe(InvoiceState::Open)
        ->and($fresh->external_id)->toBe('e9066f04-8cc7-4616-93f8-ac9ecc8479c8')
        ->and($fresh->number)->not->toBeNull()
        ->and($fresh->issued_at)->not->toBeNull();

    Http::assertSent(function ($request) {
        $body = $request->data();

        return $request->method() === 'POST'
            && $request->url() === 'https://api.lexoffice.io/v1/invoices?finalize=true'
            && $body['lineItems'][0]['name'] === 'VPS XL'
            && $body['lineItems'][0]['unitName'] === 'Stück';
    });
});

it('refuses to finalize an invoice already sent to lexoffice', function () {
    Http::fake([
        'api.lexoffice.io/v1/invoices*' => Http::response(lexInvoiceResponse(), 201),
    ]);

    $account = lexAccount();
    lexCharge($account, 1560, 'VPS XL', 'Hosting');

    $driver = lexDriver();
    $issued = $driver->issue(lexDraft($account));

    $invoice = Invoice::findOrFail($issued->invoiceId);

    expect(fn () => $driver->finalize($invoice))->toThrow(LogicException::class);
});

it('refuses to void a finalized lexoffice invoice and points at credit notes', function () {
    Http::fake([
        'api.lexoffice.io/v1/invoices*' => Http::response(lexInvoiceResponse(), 201),
    ]);

    $account = lexAccount();
    lexCharge($account, 1560, 'VPS XL', 'Hosting');

    $driver = lexDriver();
    $issued = $driver->issue(lexDraft($account));

    expect(fn () => $driver->void($issued))->toThrow(LogicException::class);
});

it('flattens a product and its sub-lines into indented lexoffice line items', function () {
    Http::fake([
        'api.lexoffice.io/v1/invoices*' => Http::response(lexInvoiceResponse(), 201),
    ]);

    $account = lexAccount();
    $group = (string) Str::uuid();

    $base = lexCharge($account, 1000, 'VPS XL', 'Hosting plan');
    $base->forceFill(['line_group' => $group, 'kind' => LineKind::Recurring])->save();

    $option = lexCharge($account, 300, 'Extra slots', 'Configurable option');
    $option->forceFill(['line_group' => $group, 'kind' => LineKind::Option])->save();

    lexDriver()->issue(lexDraft($account));

    Http::assertSent(function ($request) {
        $body = $request->data();
        $items = $body['lineItems'];

        // Parent line then its child as its own indented custom item.
        $netSum = array_sum(array_map(fn ($i) => $i['unitPrice']['netAmount'], $items));

        return count($items) === 2
            && $items[0]['name'] === 'VPS XL'
            && abs($items[0]['unitPrice']['netAmount'] - 10.0) < 0.001
            && $items[1]['name'] === '- Extra slots'
            && abs($items[1]['unitPrice']['netAmount'] - 3.0) < 0.001
            && abs($netSum - 13.0) < 0.001;   // sub-line nets sum to the subtotal
    });
});
