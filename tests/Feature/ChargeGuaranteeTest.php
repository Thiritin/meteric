<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Meteric\Contracts\InvoiceDriver;
use Meteric\Enums\ChargeState;
use Meteric\Enums\LineKind;
use Meteric\Facades\Meteric as MetericFacade;
use Meteric\Invoicing\CreditNoteDraft;
use Meteric\Invoicing\InvoiceDraft;
use Meteric\Invoicing\IssuedCreditNote;
use Meteric\Invoicing\IssuedInvoice;
use Meteric\Meteric;
use Meteric\Models\BillingAccount;
use Meteric\Models\Charge;
use Meteric\Models\Invoice;

uses(RefreshDatabase::class);

function guaranteeAccount(): BillingAccount
{
    return BillingAccount::create(['owner_type' => 'user', 'owner_id' => '1', 'currency' => 'EUR']);
}

function guaranteeCharge(BillingAccount $acc, int $minor, string $currency = 'EUR'): Charge
{
    return Charge::create([
        'account_id' => $acc->id,
        'origin_type' => 'manual', 'origin_id' => (string) Str::uuid(),
        'kind' => LineKind::OneOff, 'billing_mode' => 'in_advance',
        'state' => ChargeState::Pending, 'description' => 'Item',
        'quantity' => 1, 'unit_minor' => $minor, 'amount_minor' => $minor,
        'currency' => $currency, 'idempotency_key' => (string) Str::uuid(),
    ]);
}

/** A driver that always fails — simulates the accounting system being down. */
function throwingDriver(): InvoiceDriver
{
    return new class implements InvoiceDriver
    {
        public function issue(InvoiceDraft $draft): IssuedInvoice
        {
            throw new RuntimeException('accounting system unavailable');
        }

        public function void(IssuedInvoice $invoice): void {}

        public function creditNote(IssuedInvoice $invoice, CreditNoteDraft $draft): IssuedCreditNote
        {
            throw new RuntimeException('unavailable');
        }
    };
}

it('keeps charges pending when the invoice driver fails (the core guarantee)', function () {
    $acc = guaranteeAccount();
    guaranteeCharge($acc, 1000);
    guaranteeCharge($acc, 2000);

    $meteric = new Meteric(throwingDriver());

    expect(fn () => $meteric->invoicePending($acc))->toThrow(RuntimeException::class);

    // No invoice written, charges untouched — revenue not lost, retried next run.
    expect(Invoice::count())->toBe(0)
        ->and(Charge::where('account_id', $acc->id)->pending()->count())->toBe(2);
});

it('only bills charges in the requested currency', function () {
    $acc = guaranteeAccount();
    guaranteeCharge($acc, 1000, 'EUR');
    guaranteeCharge($acc, 5000, 'USD');

    $invoice = MetericFacade::invoicePending($acc); // defaults to account currency EUR

    expect($invoice->currency)->toBe('EUR')
        ->and($invoice->subtotal_minor)->toBe(1000)
        ->and(Charge::where('account_id', $acc->id)->where('currency', 'USD')->pending()->count())->toBe(1);
});
