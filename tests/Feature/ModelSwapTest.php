<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Meteric\Enums\ChargeState;
use Meteric\Enums\LineKind;
use Meteric\Facades\Meteric;
use Meteric\Models\BillingAccount;
use Meteric\Models\Charge;
use Meteric\Models\Invoice;
use Meteric\Support\Models;

uses(RefreshDatabase::class);

class CustomInvoice extends Invoice
{
    public function ping(): string
    {
        return 'pong';
    }
}

afterEach(fn () => Models::reset());

it('instantiates a registered override for engine-created models', function () {
    Meteric::useInvoiceModel(CustomInvoice::class);

    $account = BillingAccount::create(['owner_type' => 'user', 'owner_id' => '1', 'currency' => 'EUR']);
    Charge::create([
        'account_id' => $account->id,
        'origin_type' => 'manual', 'origin_id' => (string) Str::uuid(),
        'kind' => LineKind::Recurring, 'billing_mode' => 'in_advance',
        'state' => ChargeState::Pending, 'description' => 'x',
        'quantity' => 1, 'unit_minor' => 1000, 'amount_minor' => 1000,
        'currency' => 'EUR', 'idempotency_key' => (string) Str::uuid(),
    ]);

    $invoice = Meteric::invoicePending($account);

    expect($invoice)->toBeInstanceOf(CustomInvoice::class)
        ->and($invoice->ping())->toBe('pong');
});

it('resolves relationships to the override class', function () {
    Meteric::useInvoiceModel(CustomInvoice::class);

    $account = BillingAccount::create(['owner_type' => 'user', 'owner_id' => '2', 'currency' => 'EUR']);

    expect($account->invoices()->getRelated())->toBeInstanceOf(CustomInvoice::class);
});

it('rejects an override that does not extend the base model', function () {
    expect(fn () => Meteric::useInvoiceModel(Charge::class))
        ->toThrow(InvalidArgumentException::class);
});
