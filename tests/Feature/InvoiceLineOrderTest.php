<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Meteric\Enums\LineKind;
use Meteric\Models\BillingAccount;
use Meteric\Models\Charge;
use Meteric\Models\Invoice;
use Meteric\Models\InvoiceLine;

uses(RefreshDatabase::class);

function iloAccount(): BillingAccount
{
    return BillingAccount::create([
        'owner_type' => 'user', 'owner_id' => '1', 'currency' => 'EUR',
        'tax_profile' => ['country' => 'DE', 'merchant_country' => 'DE'],
    ]);
}

function iloInvoice(BillingAccount $account): Invoice
{
    return Invoice::create([
        'account_id' => $account->id,
        'customer_type' => 'user', 'customer_id' => '1',
        'state' => 'draft',
        'currency' => 'EUR',
        'subtotal_minor' => 0,
        'tax_minor' => 0,
        'total_minor' => 0,
    ]);
}

function iloLine(Invoice $invoice, string $title, int $sort, ?string $parentId = null, ?string $chargeId = null): InvoiceLine
{
    return InvoiceLine::create([
        'invoice_id' => $invoice->id,
        'parent_id' => $parentId,
        'charge_id' => $chargeId,
        'kind' => LineKind::Recurring,
        'title' => $title,
        'quantity' => 1,
        'unit_minor' => 1000,
        'amount_minor' => 1000,
        'tax_rate' => 19,
        'tax_minor' => 190,
        'currency' => 'EUR',
        'sort' => $sort,
    ]);
}

it('reads lines in sort order whatever order they were written in', function () {
    $invoice = iloInvoice(iloAccount());

    iloLine($invoice, 'third', 200);
    iloLine($invoice, 'first', 0);
    iloLine($invoice, 'second', 100);

    expect($invoice->lines()->pluck('title')->all())->toBe(['first', 'second', 'third']);
    expect($invoice->load('lines')->lines->pluck('title')->all())->toBe(['first', 'second', 'third']);
});

it('settles a tied sort on the key, so two reads agree', function () {
    $invoice = iloInvoice(iloAccount());

    $a = iloLine($invoice, 'written first', 0);
    $b = iloLine($invoice, 'written second', 0);

    $first = $invoice->lines()->pluck('title')->all();
    $second = $invoice->lines()->pluck('title')->all();

    expect($first)->toBe($second);
    expect($first)->toBe($a->id < $b->id
        ? ['written first', 'written second']
        : ['written second', 'written first']);
});

it('orders sub-lines under their parent', function () {
    $invoice = iloInvoice(iloAccount());
    $parent = iloLine($invoice, 'parent', 0);

    iloLine($invoice, 'option b', 2, $parent->id);
    iloLine($invoice, 'option a', 1, $parent->id);

    expect($parent->children()->pluck('title')->all())->toBe(['option a', 'option b']);
});

it('still reads the charges an ordered relation distinct-plucks', function () {
    $account = iloAccount();
    $invoice = iloInvoice($account);

    $charge = Charge::create([
        'account_id' => $account->id,
        'origin_type' => 'manual', 'origin_id' => (string) Str::uuid(),
        'kind' => LineKind::Recurring, 'billing_mode' => 'in_advance',
        'state' => 'pending', 'title' => 'a charge', 'description' => 'a charge',
        'quantity' => 1, 'unit_minor' => 1000, 'amount_minor' => 1000,
        'currency' => 'EUR', 'idempotency_key' => (string) Str::uuid(),
    ]);

    iloLine($invoice, 'billed', 0, null, $charge->id);
    iloLine($invoice, 'typed', 100);

    expect($invoice->billedCharges()->pluck('id')->all())->toBe([$charge->id]);
    expect($invoice->billedSubscriptions())->toHaveCount(0);
});
