<?php

declare(strict_types=1);

use Brick\Money\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Meteric\Enums\ChargeState;
use Meteric\Enums\InvoiceState;
use Meteric\Enums\LineKind;
use Meteric\Facades\Meteric;
use Meteric\Models\BillingAccount;
use Meteric\Models\Charge;
use Meteric\Models\CreditNoteLine;
use Meteric\Models\Invoice;
use Meteric\Models\PaymentAllocation;
use Meteric\Models\Refund;

uses(RefreshDatabase::class);

function cnlAccount(): BillingAccount
{
    return BillingAccount::create([
        'owner_type' => 'user', 'owner_id' => '1', 'currency' => 'EUR',
        'tax_profile' => ['country' => 'DE', 'merchant_country' => 'DE'],
    ]);
}

function cnlCharge(BillingAccount $account, int $amountMinor, string $desc): Charge
{
    return Charge::create([
        'account_id' => $account->id,
        'origin_type' => 'manual', 'origin_id' => (string) Str::uuid(),
        'kind' => LineKind::Recurring, 'billing_mode' => 'in_advance',
        'state' => ChargeState::Pending, 'title' => $desc, 'description' => $desc,
        'quantity' => 1, 'unit_minor' => $amountMinor, 'amount_minor' => $amountMinor,
        'currency' => 'EUR', 'idempotency_key' => (string) Str::uuid(),
    ]);
}

/** An invoice with a 10.00 and a 20.00 line at 19% VAT: net 30.00, tax 5.70, gross 35.70. */
function cnlInvoice(BillingAccount $account): Invoice
{
    cnlCharge($account, 1000, 'VPS');
    cnlCharge($account, 2000, 'Storage');

    return Meteric::invoicePending($account);
}

it('credits invoice lines at their own tax and persists lines and metadata', function () {
    $account = cnlAccount();
    $invoice = cnlInvoice($account);
    $vps = $invoice->lines()->where('amount_minor', 1000)->firstOrFail();
    $storage = $invoice->lines()->where('amount_minor', 2000)->firstOrFail();

    $note = Meteric::creditNoteLines($invoice, [
        ['invoice_line_id' => $vps->id, 'net_minor' => 500, 'title' => 'Half the VPS'],
        ['invoice_line_id' => $storage->id, 'net_minor' => 2000],
    ], 'Outage', ['ticket' => 42]);

    expect($note->amount_minor)->toBe(2500)
        ->and($note->tax_minor)->toBe(95 + 380)
        ->and($note->gross()->getMinorAmount()->toInt())->toBe(2975)
        ->and($note->reason)->toBe('Outage')
        ->and($note->metadata)->toBe(['ticket' => 42])
        ->and($note->lines)->toHaveCount(2);

    $first = $note->lines[0];
    expect($first->invoice_line_id)->toBe($vps->id)
        ->and($first->title)->toBe('Half the VPS')
        ->and($first->net_minor)->toBe(500)
        ->and($first->tax_minor)->toBe(95)
        ->and($first->tax_rate)->toBe(0.19)
        ->and($first->gross_minor)->toBe(595)
        ->and($note->lines[1]->title)->toBe($storage->title);
});

it('refuses a line credit above the remaining creditable net of that line', function () {
    $account = cnlAccount();
    $invoice = cnlInvoice($account);
    $vps = $invoice->lines()->where('amount_minor', 1000)->firstOrFail();

    Meteric::creditNoteLines($invoice, [['invoice_line_id' => $vps->id, 'net_minor' => 600]]);

    expect(fn () => Meteric::creditNoteLines($invoice, [['invoice_line_id' => $vps->id, 'net_minor' => 500]]))
        ->toThrow(InvalidArgumentException::class, 'remaining creditable net of 400');
    expect(fn () => Meteric::creditNoteLines($invoice, [
        ['invoice_line_id' => $vps->id, 'net_minor' => 300],
        ['invoice_line_id' => $vps->id, 'net_minor' => 300],
    ]))->toThrow(InvalidArgumentException::class, 'remaining creditable net of 400');

    Meteric::creditNoteLines($invoice, [['invoice_line_id' => $vps->id, 'net_minor' => 400]]);

    expect(CreditNoteLine::count())->toBe(2)
        ->and(fn () => Meteric::creditNoteLines($invoice, [['invoice_line_id' => $vps->id, 'net_minor' => 1]]))
        ->toThrow(InvalidArgumentException::class, 'remaining creditable net of 0');
});

it('refuses a line off the invoice, a non-positive line, and an empty note', function () {
    $account = cnlAccount();
    $invoice = cnlInvoice($account);
    $other = cnlInvoice(cnlAccount());
    $foreign = $other->lines()->firstOrFail();

    expect(fn () => Meteric::creditNoteLines($invoice, [['invoice_line_id' => $foreign->id, 'net_minor' => 100]]))
        ->toThrow(InvalidArgumentException::class, 'not on invoice')
        ->and(fn () => Meteric::creditNoteLines($invoice, [['invoice_line_id' => $invoice->lines()->first()->id, 'net_minor' => 0]]))
        ->toThrow(InvalidArgumentException::class, 'positive')
        ->and(fn () => Meteric::creditNoteLines($invoice, []))
        ->toThrow(InvalidArgumentException::class, 'at least one line')
        ->and(CreditNoteLine::count())->toBe(0);
});

it('keeps the single-amount credit note working and stores its metadata', function () {
    $account = cnlAccount();
    $invoice = cnlInvoice($account);

    $note = Meteric::creditNote($invoice, Money::ofMinor(1500, 'EUR'), 'Goodwill', ['by' => 'staff']);

    expect($note->amount_minor)->toBe(1500)
        ->and($note->tax_minor)->toBe(285)
        ->and($note->metadata)->toBe(['by' => 'staff'])
        ->and($note->lines)->toHaveCount(0);

    // The cumulative guard counts both shapes: 15.00 + 15.00 fills the 30.00 net.
    $vps = $invoice->lines()->where('amount_minor', 1000)->firstOrFail();
    expect(fn () => Meteric::creditNoteLines($invoice, [['invoice_line_id' => $vps->id, 'net_minor' => 1000]]))->not->toThrow(InvalidArgumentException::class)
        ->and(fn () => Meteric::creditNote($invoice, Money::ofMinor(600, 'EUR')))
        ->toThrow(InvalidArgumentException::class, 'remaining creditable net of 500');
});

it('records refunds against a payment up to its unrefunded remainder', function () {
    $account = cnlAccount();
    $invoice = cnlInvoice($account);
    $payment = Meteric::recordPayment($invoice, Money::ofMinor($invoice->total_minor, 'EUR'), 'pi_1');
    $note = Meteric::creditNote($invoice, Money::ofMinor(1000, 'EUR'), 'Refund');

    $refund = Meteric::recordRefund($payment, Money::ofMinor(1190, 'EUR'), $note, 're_1');

    expect($refund->payment_id)->toBe($payment->id)
        ->and($refund->credit_note_id)->toBe($note->id)
        ->and($refund->reference)->toBe('re_1')
        ->and($refund->amount()->getMinorAmount()->toInt())->toBe(1190)
        ->and($payment->refundedMinor())->toBe(1190)
        ->and($payment->refundable()->getMinorAmount()->toInt())->toBe(3570 - 1190)
        ->and($note->refunds()->count())->toBe(1);

    expect(fn () => Meteric::recordRefund($payment, Money::ofMinor(2381, 'EUR')))
        ->toThrow(InvalidArgumentException::class, 'unrefunded remainder of 2380');
    expect(fn () => Meteric::recordRefund($payment, Money::ofMinor(100, 'USD')))
        ->toThrow(InvalidArgumentException::class, 'currency');
    expect(fn () => Meteric::recordRefund($payment, Money::ofMinor(0, 'EUR')))
        ->toThrow(InvalidArgumentException::class, 'positive');

    Meteric::recordRefund($payment, Money::ofMinor(2380, 'EUR'));

    // The payment, its allocation and the invoice are untouched.
    expect($payment->fresh()->amount_minor)->toBe(3570)
        ->and($payment->refundedMinor())->toBe(3570)
        ->and(PaymentAllocation::where('payment_id', $payment->id)->sum('amount_minor'))->toEqual(3570)
        ->and($invoice->fresh()->paid_minor)->toBe(3570)
        ->and($invoice->fresh()->state)->toBe(InvoiceState::Paid)
        ->and(Refund::count())->toBe(2);
});

it('voids an invoice reverting its charges, or voiding them when asked', function () {
    $account = cnlAccount();
    $invoice = cnlInvoice($account);
    Meteric::voidInvoice($invoice);

    expect(Charge::where('account_id', $account->id)->pending()->count())->toBe(2)
        ->and($invoice->fresh()->state)->toBe(InvoiceState::Void);

    $reissued = Meteric::invoicePending($account);
    expect($reissued->subtotal_minor)->toBe(3000);

    Meteric::voidInvoice($reissued, voidCharges: true);

    expect(Charge::where('account_id', $account->id)->where('state', ChargeState::Void->value)->count())->toBe(2)
        ->and(Charge::where('account_id', $account->id)->pending()->count())->toBe(0)
        ->and(Meteric::invoicePending($account))->toBeNull();
});
