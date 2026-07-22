<?php

declare(strict_types=1);

use Brick\Money\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Meteric\Contracts\Clock;
use Meteric\Contracts\InvoiceDriver;
use Meteric\Contracts\TaxResolver;
use Meteric\Enums\ChargeState;
use Meteric\Enums\CreditState;
use Meteric\Enums\InvoiceState;
use Meteric\Enums\LineKind;
use Meteric\Facades\Meteric;
use Meteric\Invoicing\CreditNoteDraft;
use Meteric\Invoicing\Drivers\DatabaseInvoiceDriver;
use Meteric\Invoicing\IssuedInvoice;
use Meteric\Models\BillingAccount;
use Meteric\Models\Charge;
use Meteric\Models\CreditNote;
use Meteric\Models\Invoice;

uses(RefreshDatabase::class);

function settlementAccount(): BillingAccount
{
    return BillingAccount::create([
        'owner_type' => 'user', 'owner_id' => '1', 'currency' => 'EUR',
        'tax_profile' => ['country' => 'DE', 'merchant_country' => 'DE'],
    ]);
}

function settlementCharge(BillingAccount $account, int $amountMinor, string $desc): Charge
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

function settlementDriver(): DatabaseInvoiceDriver
{
    $resolved = app(InvoiceDriver::class);

    return $resolved instanceof DatabaseInvoiceDriver
        ? $resolved
        : new DatabaseInvoiceDriver(app(TaxResolver::class), app(Clock::class));
}

it('leaves an invoice partially paid then flips to paid on the remainder', function () {
    $account = settlementAccount();
    settlementCharge($account, 1000, 'VPS');
    $invoice = Meteric::invoicePending($account);
    $total = $invoice->total_minor;

    // First payment for less than the total.
    $first = (int) floor($total / 3);
    Meteric::recordPayment($invoice, Money::ofMinor($first, 'EUR'), 'pi_partial_1');

    $invoice->refresh();
    expect($invoice->state)->toBe(InvoiceState::PartiallyPaid)
        ->and($invoice->paid_minor)->toBe($first)
        ->and($invoice->outstanding()->getMinorAmount()->toInt())->toBe($total - $first)
        ->and($invoice->paid_at)->toBeNull();

    // Second payment clears the rest.
    Meteric::recordPayment($invoice, Money::ofMinor($total - $first, 'EUR'), 'pi_partial_2');

    $invoice->refresh();
    expect($invoice->state)->toBe(InvoiceState::Paid)
        ->and($invoice->paid_minor)->toBe($total)
        ->and($invoice->outstanding()->getMinorAmount()->toInt())->toBe(0)
        ->and($invoice->paid_at)->not->toBeNull();
});

it('issues a credit note against an issued invoice', function () {
    $account = settlementAccount();
    settlementCharge($account, 1000, 'VPS');
    $invoice = Meteric::invoicePending($account);

    $driver = settlementDriver();
    $issued = new IssuedInvoice(invoiceId: $invoice->id, number: $invoice->number);

    $result = $driver->creditNote($issued, new CreditNoteDraft(
        amount: Money::ofMinor(400, 'EUR'),
        reason: 'Goodwill',
    ));

    $note = CreditNote::findOrFail($result->creditNoteId);
    expect($note->invoice_id)->toBe($invoice->id)
        ->and($note->state)->toBe(CreditState::Issued)
        ->and($note->amount_minor)->toBe(400)
        ->and($note->amount()->getMinorAmount()->toInt())->toBe(400)
        ->and($note->reason)->toBe('Goodwill')
        ->and($note->number)->toStartWith('CN-');

    expect($invoice->creditNotes()->count())->toBe(1);
});

it('voids an open unpaid invoice', function () {
    $account = settlementAccount();
    settlementCharge($account, 1000, 'VPS');
    $invoice = Meteric::invoicePending($account);

    expect($invoice->state)->toBe(InvoiceState::Open);

    $driver = settlementDriver();
    $driver->void(new IssuedInvoice(invoiceId: $invoice->id, number: $invoice->number));

    expect(Invoice::findOrFail($invoice->id)->state)->toBe(InvoiceState::Void);
});
