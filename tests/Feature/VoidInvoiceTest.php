<?php

declare(strict_types=1);

use Brick\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Meteric\Enums\ChargeState;
use Meteric\Enums\InvoiceState;
use Meteric\Events\InvoiceVoided;
use Meteric\Facades\Meteric;
use Meteric\Models\BillingAccount;
use Meteric\Models\Charge;
use Meteric\Models\InvoiceLine;
use Meteric\Models\Price;
use Meteric\Models\Product;

uses(RefreshDatabase::class);

function vinInvoice(): array
{
    $account = BillingAccount::create(['owner_type' => 'user', 'owner_id' => '1', 'currency' => 'EUR']);
    $product = Product::create(['type' => 'vps', 'slug' => 'vin-'.uniqid(), 'name' => 'VPS', 'pricing_model' => 'fixed']);
    $price = Price::create([
        'product_id' => $product->id, 'currency' => 'EUR', 'amount_minor' => 2000,
        'pricing_model' => 'fixed', 'interval' => 'month', 'interval_count' => 1,
    ]);
    Meteric::subscribe()->account($account)->at(CarbonImmutable::parse('2026-06-01Z'))->add($price, 1)->create();
    $invoice = Meteric::invoicePending($account);

    return [$account, $invoice];
}

it('voids an unpaid invoice and reverts its charges to pending', function () {
    [$account, $invoice] = vinInvoice();

    $chargeIds = InvoiceLine::where('invoice_id', $invoice->id)->whereNotNull('charge_id')->pluck('charge_id');
    expect($chargeIds)->not->toBeEmpty()
        ->and(Charge::whereIn('id', $chargeIds)->where('state', ChargeState::Invoiced->value)->count())->toBe($chargeIds->count());

    Event::fake([InvoiceVoided::class]);
    Meteric::voidInvoice($invoice);

    expect($invoice->fresh()->state)->toBe(InvoiceState::Void)
        // Every charge returns to the billable pool: never-lose-a-charge.
        ->and(Charge::whereIn('id', $chargeIds)->where('state', ChargeState::Pending->value)->count())->toBe($chargeIds->count());
    Event::assertDispatched(InvoiceVoided::class);

    // The reverted charge is billable again.
    $reissued = Meteric::invoicePending($account);
    expect($reissued)->not->toBeNull()
        ->and($reissued->subtotal_minor)->toBe(2000);
});

it('does not revert a charge that still has a live line on another invoice', function () {
    [, $invoice] = vinInvoice();

    $chargeIds = InvoiceLine::where('invoice_id', $invoice->id)->whereNotNull('charge_id')->pluck('charge_id');

    // Copy the invoice (clones lines keeping charge_id), then void the source.
    $copy = Meteric::copyInvoice($invoice);
    Meteric::voidInvoice($invoice);

    // The charges keep a live line on the copy, so they stay invoiced.
    expect($invoice->fresh()->state)->toBe(InvoiceState::Void)
        ->and(Charge::whereIn('id', $chargeIds)->where('state', ChargeState::Invoiced->value)->count())->toBe($chargeIds->count())
        ->and(InvoiceLine::where('invoice_id', $copy->id)->whereNotNull('charge_id')->count())->toBe($chargeIds->count());
});

it('refuses to void a paid invoice and points to a credit note', function () {
    [, $invoice] = vinInvoice();
    Meteric::recordPayment($invoice, Money::ofMinor($invoice->total_minor, 'EUR'));

    expect(fn () => Meteric::voidInvoice($invoice->fresh()))->toThrow(LogicException::class);
});

it('does not revert a settled charge when its invoice is later voided', function () {
    // A paid invoice cannot be voided, but guard the Charge transition directly:
    // a settled charge never reverts.
    [, $invoice] = vinInvoice();
    Meteric::recordPayment($invoice, Money::ofMinor($invoice->total_minor, 'EUR'));

    $charge = Charge::whereIn(
        'id',
        InvoiceLine::where('invoice_id', $invoice->id)->whereNotNull('charge_id')->pluck('charge_id')
    )->first();

    expect($charge->state)->toBe(ChargeState::Settled);
    $charge->revertToPending();
    expect($charge->fresh()->state)->toBe(ChargeState::Settled);   // no-op on settled
});
