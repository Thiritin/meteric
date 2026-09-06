<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Meteric\Enums\ChargeState;
use Meteric\Enums\LineKind;
use Meteric\Facades\Meteric;
use Meteric\Models\BillingAccount;
use Meteric\Models\Charge;

uses(RefreshDatabase::class);

function profileAccount(array $profile): BillingAccount
{
    return BillingAccount::create([
        'owner_type' => 'user',
        'owner_id' => (string) Str::uuid(),
        'currency' => 'EUR',
        'tax_profile' => $profile,
    ]);
}

function profileCharge(BillingAccount $account, int $minor = 10000): Charge
{
    return Charge::create([
        'account_id' => $account->id,
        'origin_type' => 'manual', 'origin_id' => (string) Str::uuid(),
        'kind' => LineKind::OneOff, 'billing_mode' => 'in_advance',
        'state' => ChargeState::Pending, 'title' => 'Hosting', 'description' => 'Hosting',
        'quantity' => 1, 'unit_minor' => $minor, 'amount_minor' => $minor,
        'currency' => 'EUR', 'idempotency_key' => (string) Str::uuid(),
    ]);
}

it('records the profile the lines were priced under', function () {
    $account = profileAccount(['country' => 'DE', 'b2b' => false, 'merchant_country' => 'DE']);
    profileCharge($account);

    $invoice = Meteric::invoicePending($account);

    expect($invoice->tax_profile)->toEqual(['country' => 'DE', 'b2b' => false, 'merchant_country' => 'DE']);
    expect($invoice->taxContext()->countryCode)->toBe('DE');
});

it('keeps the recorded profile when the account moves country', function () {
    $account = profileAccount(['country' => 'DE', 'b2b' => false, 'merchant_country' => 'DE']);
    profileCharge($account);

    $invoice = Meteric::invoicePending($account);

    $account->forceFill(['tax_profile' => ['country' => 'US', 'b2b' => true, 'vat_id' => 'US1', 'merchant_country' => 'DE']])->save();

    expect($invoice->fresh()->taxProfile()['country'])->toBe('DE');
});

it('refuses to change the profile of an issued invoice', function () {
    $account = profileAccount(['country' => 'DE', 'b2b' => false]);
    profileCharge($account);

    $invoice = Meteric::invoicePending($account);

    expect(fn () => $invoice->forceFill(['tax_profile' => ['country' => 'US']])->save())
        ->toThrow(QueryException::class, 'tax profile is immutable');
});

it('reprices a draft and stamps it at finalize', function () {
    $account = profileAccount(['country' => 'DE', 'b2b' => false]);

    $draft = Meteric::createInvoice($account);

    expect($draft->tax_profile)->toBeNull();

    $account->forceFill(['tax_profile' => ['country' => 'AT', 'b2b' => true, 'vat_id' => 'ATU1']])->save();

    $invoice = Meteric::finalizeInvoice($draft->fresh());

    expect($invoice->tax_profile['country'])->toBe('AT');
});

it('falls back to the account where nothing was recorded', function () {
    $account = profileAccount(['country' => 'DE', 'b2b' => false]);

    $draft = Meteric::createInvoice($account);

    expect($draft->tax_profile)->toBeNull();
    expect($draft->taxProfile()['country'])->toBe('DE');
});

it('carries the source profile onto a copy', function () {
    $account = profileAccount(['country' => 'DE', 'b2b' => false]);
    profileCharge($account);

    $source = Meteric::invoicePending($account);

    $account->forceFill(['tax_profile' => ['country' => 'US', 'b2b' => false]])->save();

    expect(Meteric::copyInvoice($source)->tax_profile['country'])->toBe('DE');
});
