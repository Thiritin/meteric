<?php

declare(strict_types=1);

use Brick\Money\Money;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Meteric\Enums\ChargeState;
use Meteric\Enums\InvoiceState;
use Meteric\Enums\LineKind;
use Meteric\Facades\Meteric;
use Meteric\Models\BillingAccount;
use Meteric\Models\Charge;
use Meteric\Models\Invoice;

uses(RefreshDatabase::class);

function germanAccount(): BillingAccount
{
    return BillingAccount::create([
        'owner_type' => 'user', 'owner_id' => '1', 'currency' => 'EUR',
        'tax_profile' => ['country' => 'DE', 'merchant_country' => 'DE'],
    ]);
}

function pendingCharge(BillingAccount $account, int $amountMinor, string $desc): Charge
{
    return Charge::create([
        'account_id' => $account->id,
        'origin_type' => 'manual', 'origin_id' => (string) Str::uuid(),
        'kind' => LineKind::Recurring, 'billing_mode' => 'in_advance',
        'state' => ChargeState::Pending, 'description' => $desc,
        'quantity' => 1, 'unit_minor' => $amountMinor, 'amount_minor' => $amountMinor,
        'currency' => 'EUR', 'idempotency_key' => (string) Str::uuid(),
    ]);
}

it('invoices pending charges and flips them to invoiced', function () {
    $account = germanAccount();
    pendingCharge($account, 1000, 'VPS XL');
    pendingCharge($account, 480, 'Gameserver slots');

    $invoice = Meteric::invoicePending($account);

    expect($invoice)->not->toBeNull()
        ->and($invoice->state)->toBe(InvoiceState::Open)
        ->and($invoice->subtotal_minor)->toBe(1480)
        ->and($invoice->tax_minor)->toBe(281)        // 19% of 14.80 = 2.812 -> rounded per line
        ->and($invoice->total_minor)->toBe(1480 + 281)
        ->and($invoice->lines)->toHaveCount(2)
        ->and($invoice->number)->not->toBeNull();

    expect(Charge::where('account_id', $account->id)->pending()->count())->toBe(0)
        ->and(Charge::where('account_id', $account->id)->where('state', ChargeState::Invoiced->value)->count())->toBe(2);
});

it('returns null when nothing is pending', function () {
    expect(Meteric::invoicePending(germanAccount()))->toBeNull();
});

it('records a payment and marks the invoice paid', function () {
    $account = germanAccount();
    pendingCharge($account, 1000, 'VPS');
    $invoice = Meteric::invoicePending($account);

    Meteric::recordPayment($invoice, Money::ofMinor($invoice->total_minor, 'EUR'), 'pi_test_1');

    $invoice->refresh();
    expect($invoice->state)->toBe(InvoiceState::Paid)
        ->and($invoice->paid_minor)->toBe($invoice->total_minor);
});

it('freezes an issued invoice (immutability trigger)', function () {
    $account = germanAccount();
    pendingCharge($account, 1000, 'VPS');
    $invoice = Meteric::invoicePending($account);

    // Tamper with financials of an issued invoice → trigger raises.
    Invoice::query()->whereKey($invoice->id)->update(['total_minor' => 1]);
})->throws(QueryException::class);
