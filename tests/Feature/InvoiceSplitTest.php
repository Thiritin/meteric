<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Meteric\Enums\ChargeState;
use Meteric\Enums\InvoiceSplit;
use Meteric\Enums\LineKind;
use Meteric\Facades\Meteric;
use Meteric\Models\BillingAccount;
use Meteric\Models\Charge;
use Meteric\Models\Subscription;

uses(RefreshDatabase::class);

function splitAccount(): BillingAccount
{
    return BillingAccount::create(['owner_type' => 'user', 'owner_id' => (string) Str::uuid(), 'currency' => 'EUR']);
}

function splitSubscription(BillingAccount $account): string
{
    return Subscription::create([
        'account_id' => $account->id,
        'customer_type' => 'user',
        'customer_id' => '1',
        'currency' => 'EUR',
    ])->id;
}

function splitCharge(BillingAccount $account, int $minor, string $title, ?string $subscriptionId = null): Charge
{
    return Charge::create([
        'account_id' => $account->id,
        'subscription_id' => $subscriptionId,
        'origin_type' => 'manual', 'origin_id' => (string) Str::uuid(),
        'kind' => LineKind::OneOff, 'billing_mode' => 'in_advance',
        'state' => ChargeState::Pending, 'title' => $title, 'description' => $title,
        'quantity' => 1, 'unit_minor' => $minor, 'amount_minor' => $minor,
        'currency' => 'EUR', 'idempotency_key' => (string) Str::uuid(),
    ]);
}

it('pools the whole pending set onto one invoice by default', function () {
    $account = splitAccount();

    expect($account->invoice_split)->toBe(InvoiceSplit::Pooled);

    splitCharge($account, 1000, 'VPS', splitSubscription($account));
    splitCharge($account, 2000, 'Webspace', splitSubscription($account));

    $invoices = Meteric::invoiceAllPending($account);

    expect($invoices)->toHaveCount(1)
        ->and($invoices[0]->subtotal_minor)->toBe(3000);
});

it('writes one invoice per subscription when the account asks for it', function () {
    $account = splitAccount();
    Meteric::setInvoiceSplit($account, InvoiceSplit::PerSubscription);

    $first = splitSubscription($account);
    $second = splitSubscription($account);

    splitCharge($account, 1000, 'VPS', $first);
    splitCharge($account, 500, 'VPS backup', $first);
    splitCharge($account, 2000, 'Webspace', $second);

    $invoices = Meteric::invoiceAllPending($account->refresh());

    expect($invoices)->toHaveCount(2)
        ->and(collect($invoices)->pluck('subtotal_minor')->sort()->values()->all())->toBe([1500, 2000]);
});

// A charge that belongs to no subscription has nothing to be split by, and
// dropping it would strand it pending for ever.
it('puts the charges with no subscription on one document of their own', function () {
    $account = splitAccount();
    Meteric::setInvoiceSplit($account, InvoiceSplit::PerSubscription);

    splitCharge($account, 1000, 'VPS', splitSubscription($account));
    splitCharge($account, 700, 'Restore fee');
    splitCharge($account, 300, 'Manual adjustment');

    $invoices = Meteric::invoiceAllPending($account->refresh());

    expect($invoices)->toHaveCount(2)
        ->and(collect($invoices)->pluck('subtotal_minor')->sort()->values()->all())->toBe([1000, 1000]);
});

// The split is about how many documents, never about when one is written.
it('leaves invoicePending issuing exactly one document', function () {
    $account = splitAccount();
    Meteric::setInvoiceSplit($account, InvoiceSplit::PerSubscription);

    splitCharge($account, 1000, 'VPS', splitSubscription($account));
    splitCharge($account, 2000, 'Webspace', splitSubscription($account));

    $invoice = Meteric::invoicePending($account->refresh());

    expect($invoice)->not->toBeNull()
        ->and($invoice->subtotal_minor)->toBe(3000);
});
