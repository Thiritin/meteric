<?php

declare(strict_types=1);

use Brick\Money\Money;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Meteric\Enums\ChargeState;
use Meteric\Enums\InvoiceState;
use Meteric\Enums\LineKind;
use Meteric\Events\PaymentReversed;
use Meteric\Exceptions\PaymentNotReversible;
use Meteric\Facades\Meteric;
use Meteric\Models\BillingAccount;
use Meteric\Models\Charge;
use Meteric\Models\Payment;
use Meteric\Models\PaymentAllocation;

uses(RefreshDatabase::class);

function reversalAccount(): BillingAccount
{
    return BillingAccount::create([
        'owner_type' => 'user', 'owner_id' => '1', 'currency' => 'EUR',
        'tax_profile' => ['country' => 'DE', 'merchant_country' => 'DE'],
    ]);
}

function reversalCharge(BillingAccount $account, int $amountMinor): Charge
{
    return Charge::create([
        'account_id' => $account->id,
        'origin_type' => 'manual', 'origin_id' => (string) Str::uuid(),
        'kind' => LineKind::Recurring, 'billing_mode' => 'in_advance',
        'state' => ChargeState::Pending, 'description' => 'VPS',
        'quantity' => 1, 'unit_minor' => $amountMinor, 'amount_minor' => $amountMinor,
        'currency' => 'EUR', 'idempotency_key' => (string) Str::uuid(),
    ]);
}

it('returns a paid invoice to open and unsettles its charges', function () {
    $account = reversalAccount();
    $charge = reversalCharge($account, 1000);
    $invoice = Meteric::invoicePending($account);
    $payment = Meteric::recordPayment($invoice, Money::ofMinor($invoice->total_minor, 'EUR'), 'pi_reverse_1');

    expect($invoice->refresh()->state)->toBe(InvoiceState::Paid)
        ->and($charge->refresh()->state)->toBe(ChargeState::Settled);

    Meteric::reversePayment($payment, 'AC04 account closed');

    $invoice->refresh();
    expect($invoice->state)->toBe(InvoiceState::Open)
        ->and($invoice->paid_minor)->toBe(0)
        ->and($invoice->paid_at)->toBeNull()
        ->and($invoice->outstanding()->getMinorAmount()->toInt())->toBe((int) $invoice->total_minor)
        ->and($charge->refresh()->state)->toBe(ChargeState::Invoiced);
});

it('writes a counter allocation and leaves the original standing', function () {
    $account = reversalAccount();
    reversalCharge($account, 1000);
    $invoice = Meteric::invoicePending($account);
    $payment = Meteric::recordPayment($invoice, Money::ofMinor($invoice->total_minor, 'EUR'), 'pi_reverse_2');

    $original = $payment->allocations()->applications()->sole();

    Meteric::reversePayment($payment, 'MD06 refund requested');

    $reversal = PaymentAllocation::query()->where('reverses_allocation_id', $original->id)->sole();

    expect($original->refresh()->amount_minor)->toBe((int) $invoice->total_minor)
        ->and($reversal->amount_minor)->toBe(-(int) $invoice->total_minor)
        ->and($reversal->reason)->toBe('MD06 refund requested')
        ->and($reversal->recorded_at)->not->toBeNull()
        ->and((int) $invoice->payments()->sum('amount_minor'))->toBe(0)
        ->and($payment->refresh()->allocatedMinor())->toBe(0)
        ->and($payment->reversedMinor())->toBe((int) $invoice->total_minor)
        ->and($payment->isReversed())->toBeTrue();
});

it('leaves an invoice partially paid when only part of it was paid', function () {
    $account = reversalAccount();
    $charge = reversalCharge($account, 1000);
    $invoice = Meteric::invoicePending($account);
    $total = (int) $invoice->total_minor;

    Meteric::recordPayment($invoice, Money::ofMinor($total - 100, 'EUR'), 'pi_reverse_3a');
    $second = Meteric::recordPayment($invoice, Money::ofMinor(100, 'EUR'), 'pi_reverse_3b');

    expect($charge->refresh()->state)->toBe(ChargeState::Settled);

    Meteric::reversePayment($second, 'AC01 incorrect account number');

    $invoice->refresh();
    expect($invoice->state)->toBe(InvoiceState::PartiallyPaid)
        ->and($invoice->paid_minor)->toBe($total - 100)
        ->and($invoice->paid_at)->toBeNull()
        ->and($charge->refresh()->state)->toBe(ChargeState::Invoiced);
});

it('dispatches PaymentReversed with the payment, the invoice and the reason', function () {
    Event::fake([PaymentReversed::class]);

    $account = reversalAccount();
    reversalCharge($account, 1000);
    $invoice = Meteric::invoicePending($account);
    $payment = Meteric::recordPayment($invoice, Money::ofMinor($invoice->total_minor, 'EUR'), 'pi_reverse_4');

    Meteric::reversePayment($payment, 'AM04 insufficient funds');

    Event::assertDispatched(PaymentReversed::class, function (PaymentReversed $event) use ($payment, $invoice): bool {
        return $event->payment->id === $payment->id
            && $event->invoice->id === $invoice->id
            && $event->reason === 'AM04 insufficient funds'
            && $event->reversal->amount_minor === -(int) $invoice->total_minor;
    });
});

it('refuses to reverse the same payment twice', function () {
    $account = reversalAccount();
    reversalCharge($account, 1000);
    $invoice = Meteric::invoicePending($account);
    $payment = Meteric::recordPayment($invoice, Money::ofMinor($invoice->total_minor, 'EUR'), 'pi_reverse_5');

    Meteric::reversePayment($payment, 'AC04 account closed');

    expect(fn () => Meteric::reversePayment($payment, 'AC04 account closed'))
        ->toThrow(PaymentNotReversible::class);

    expect((int) PaymentAllocation::query()->where('invoice_id', $invoice->id)->count())->toBe(2)
        ->and($invoice->refresh()->paid_minor)->toBe(0);
});

it('refuses a payment allocated to nothing', function () {
    $account = reversalAccount();
    $payment = Payment::create([
        'account_id' => $account->id,
        'amount_minor' => 500,
        'currency' => 'EUR',
        'reference' => 'pi_reverse_6',
    ]);

    expect(fn () => Meteric::reversePayment($payment, 'never allocated'))
        ->toThrow(PaymentNotReversible::class);
});

it('refuses a positive reversal row and a negative application at the database', function () {
    $account = reversalAccount();
    reversalCharge($account, 1000);
    $invoice = Meteric::invoicePending($account);
    $payment = Meteric::recordPayment($invoice, Money::ofMinor($invoice->total_minor, 'EUR'), 'pi_reverse_7');
    $original = $payment->allocations()->applications()->sole();

    expect(fn () => PaymentAllocation::query()->create([
        'payment_id' => $payment->id,
        'invoice_id' => $invoice->id,
        'amount_minor' => 100,
        'reverses_allocation_id' => $original->id,
    ]))->toThrow(QueryException::class);

    expect(fn () => PaymentAllocation::query()->create([
        'payment_id' => $payment->id,
        'invoice_id' => $invoice->id,
        'amount_minor' => -100,
    ]))->toThrow(QueryException::class);
});
