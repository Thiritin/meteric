<?php

declare(strict_types=1);

use Brick\Money\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Meteric\Contracts\Clock;
use Meteric\Enums\ChargeState;
use Meteric\Enums\InvoiceState;
use Meteric\Enums\LineKind;
use Meteric\Facades\Meteric;
use Meteric\Models\BillingAccount;
use Meteric\Models\Charge;
use Meteric\Models\Invoice;
use Meteric\Support\FrozenClock;

uses(RefreshDatabase::class);

function guardAccount(string $currency = 'EUR'): BillingAccount
{
    return BillingAccount::create([
        'owner_type' => 'user', 'owner_id' => '1', 'currency' => $currency,
        'tax_profile' => ['country' => 'DE', 'merchant_country' => 'DE'],
    ]);
}

function guardCharge(BillingAccount $account, int $amountMinor, string $currency = 'EUR'): Charge
{
    return Charge::create([
        'account_id' => $account->id,
        'origin_type' => 'manual', 'origin_id' => (string) Str::uuid(),
        'kind' => LineKind::Recurring, 'billing_mode' => 'in_advance',
        'state' => ChargeState::Pending, 'description' => 'x',
        'quantity' => 1, 'unit_minor' => $amountMinor, 'amount_minor' => $amountMinor,
        'currency' => $currency, 'idempotency_key' => (string) Str::uuid(),
    ]);
}

it('rejects a payment whose currency differs from the invoice', function () {
    $account = guardAccount('EUR');
    guardCharge($account, 1000);
    $invoice = Meteric::invoicePending($account);

    expect(fn () => Meteric::recordPayment($invoice, Money::ofMinor($invoice->total_minor, 'USD')))
        ->toThrow(InvalidArgumentException::class);

    expect($invoice->fresh()->state)->toBe(InvoiceState::Open)
        ->and($invoice->fresh()->paid_minor)->toBe(0);
});

it('refuses to bill a charge that is no longer pending (concurrent guard)', function () {
    $account = guardAccount();
    $charge = guardCharge($account, 1000);

    // First run bills it.
    Meteric::invoicePending($account);
    expect($charge->fresh()->state)->toBe(ChargeState::Invoiced);

    // A stale in-memory copy of the same charge cannot be re-billed: markInvoiced
    // only flips a row still in the pending state.
    expect(fn () => $charge->markInvoiced())->toThrow(RuntimeException::class);
});

it('numbers invoices sequentially per year and never reuses a number', function () {
    $account = guardAccount();

    guardCharge($account, 1000);
    $first = Meteric::invoicePending($account);

    guardCharge($account, 2000);
    $second = Meteric::invoicePending($account);

    $year = now()->year;
    expect($first->number)->toBe(sprintf('INV-%d-000001', $year))
        ->and($second->number)->toBe(sprintf('INV-%d-000002', $year));

    // Voiding the second does not free its number; the next issue moves forward.
    Meteric::voidInvoice($second);

    guardCharge($account, 3000);
    $third = Meteric::invoicePending($account);

    expect($third->number)->toBe(sprintf('INV-%d-000003', $year))
        ->and(Invoice::where('number', $second->number)->count())->toBe(1);
});

it('refuses to credit more than the invoice net across multiple notes', function () {
    $account = guardAccount();
    guardCharge($account, 1000);              // net 1000
    $invoice = Meteric::invoicePending($account);

    Meteric::creditNote($invoice, Money::ofMinor(600, 'EUR'), 'partial');

    // 600 already credited; a further 600 would exceed the 1000 net.
    expect(fn () => Meteric::creditNote($invoice, Money::ofMinor(600, 'EUR'), 'again'))
        ->toThrow(InvalidArgumentException::class);

    // The remaining 400 is allowed.
    $ok = Meteric::creditNote($invoice, Money::ofMinor(400, 'EUR'), 'rest');
    expect($ok->amount_minor)->toBe(400);

    expect(fn () => Meteric::creditNote($invoice, Money::ofMinor(1, 'EUR'), 'over'))
        ->toThrow(InvalidArgumentException::class);
});

it('dates invoices from the injected clock, not wall time', function () {
    app()->instance(Clock::class, FrozenClock::at('2031-05-10T00:00:00Z'));

    $account = guardAccount();
    guardCharge($account, 1000);
    $invoice = Meteric::invoicePending($account);

    expect($invoice->issued_at->toDateString())->toBe('2031-05-10')
        ->and($invoice->number)->toStartWith('INV-2031-');
});

it('stamps a due date on every issued invoice so it can go overdue', function () {
    config(['meteric.invoice.net_days' => 7]);
    $account = guardAccount();
    guardCharge($account, 1000);

    $invoice = Meteric::invoicePending($account);

    expect($invoice->due_at)->not->toBeNull()
        ->and($invoice->due_at->toDateString())
        ->toBe($invoice->issued_at->copy()->addDays(7)->toDateString());
});

it('invoices every currency with pending charges, not just the account default', function () {
    $account = guardAccount('EUR');
    guardCharge($account, 1000, 'EUR');
    guardCharge($account, 2000, 'USD');

    $invoices = Meteric::invoiceAllPending($account);

    expect($invoices)->toHaveCount(2)
        ->and(collect($invoices)->pluck('currency')->sort()->values()->all())->toBe(['EUR', 'USD'])
        ->and(Charge::where('account_id', $account->id)->pending()->count())->toBe(0);
});

it('credits tax at the invoice blended rate, proportional to the credited net', function () {
    $account = guardAccount();
    guardCharge($account, 1000);           // 19% -> subtotal 1000, tax 190
    $invoice = Meteric::invoicePending($account);

    expect($invoice->tax_minor)->toBe(190);

    // Credit half the net: tax reverses proportionally (500 * 190/1000 = 95).
    $note = Meteric::creditNote($invoice, Money::ofMinor(500, 'EUR'), 'partial');

    expect($note->amount_minor)->toBe(500)
        ->and($note->tax_minor)->toBe(95);
});

it('does not double-bill when the same pending set is invoiced twice in sequence', function () {
    $account = guardAccount();
    guardCharge($account, 1000);
    guardCharge($account, 500);

    $first = Meteric::invoicePending($account);
    $second = Meteric::invoicePending($account);

    expect($first)->not->toBeNull()
        ->and($second)->toBeNull()  // nothing left pending
        ->and(Invoice::where('account_id', $account->id)->where('state', InvoiceState::Open->value)->count())->toBe(1)
        ->and(Charge::where('account_id', $account->id)->where('state', ChargeState::Invoiced->value)->count())->toBe(2);
});
