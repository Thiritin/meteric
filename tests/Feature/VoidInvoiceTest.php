<?php

declare(strict_types=1);

use Brick\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Meteric\Enums\ChargeState;
use Meteric\Enums\InvoiceState;
use Meteric\Events\InvoiceVoided;
use Meteric\Facades\Meteric;
use Meteric\Models\BillingAccount;
use Meteric\Models\Charge;
use Meteric\Models\Invoice;
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

/**
 * A savepoint around a statement Postgres rejects: without one the rejection
 * aborts the test's own transaction and every assertion after it fails on that
 * rather than on the rule.
 */
function vinRefused(Closure $write): Closure
{
    return fn () => DB::transaction($write);
}

it('refuses to void a paid invoice handed in as a model from before the payment', function () {
    [, $invoice] = vinInvoice();

    // The caller's copy, taken before the payment was collected, still says
    // nothing is paid. The rule is about the invoice, not about the copy.
    $stale = clone $invoice;
    Meteric::recordPayment($invoice, Money::ofMinor($invoice->total_minor, 'EUR'));

    expect($stale->paid_minor)->toBe(0);

    expect(fn () => Meteric::voidInvoice($stale))
        ->toThrow(LogicException::class, 'Cannot void an invoice with payments');

    expect($invoice->fresh()->state)->toBe(InvoiceState::Paid);
});

it('refuses to void an invoice with payments in the database as well', function () {
    [, $invoice] = vinInvoice();
    Meteric::recordPayment($invoice, Money::ofMinor(1000, 'EUR'));

    expect($invoice->fresh()->state)->toBe(InvoiceState::PartiallyPaid);

    // The manager's rule reached by a statement that never sees the manager. A
    // settled document cancelled in place leaves a payment allocated to an
    // invoice that officially never existed; a credit note states the reversal.
    expect(vinRefused(fn () => DB::table((new Invoice)->getTable())
        ->where('id', $invoice->id)
        ->update(['state' => InvoiceState::Void->value])))
        ->toThrow(QueryException::class, 'corrected by a credit note');

    Meteric::recordPayment($invoice->fresh(), Money::ofMinor(1380, 'EUR'));

    expect($invoice->fresh()->state)->toBe(InvoiceState::Paid);

    expect(vinRefused(fn () => DB::table((new Invoice)->getTable())
        ->where('id', $invoice->id)
        ->update(['state' => InvoiceState::Void->value])))
        ->toThrow(QueryException::class, 'corrected by a credit note');

    expect($invoice->fresh()->state)->toBe(InvoiceState::Paid);
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

it('refuses to put an issued invoice back into draft', function () {
    [, $invoice] = vinInvoice();

    expect($invoice->state)->toBe(InvoiceState::Open);

    // Every figure computed over issued documents excludes a draft, so this is
    // an issued document being unwritten rather than a lifecycle transition.
    expect(vinRefused(fn () => DB::table((new Invoice)->getTable())
        ->where('id', $invoice->id)
        ->update(['state' => InvoiceState::Draft->value])))
        ->toThrow(QueryException::class, 'does not become a draft again');

    expect($invoice->fresh()->state)->toBe(InvoiceState::Open);
});

it('refuses to move the day an issued invoice was issued', function () {
    [, $invoice] = vinInvoice();

    $issued = $invoice->issued_at;

    // The tax point. Moving it reassigns the supply to another period while
    // every total over the year stays exactly the same.
    expect(vinRefused(fn () => DB::table((new Invoice)->getTable())
        ->where('id', $invoice->id)
        ->update(['issued_at' => $issued->addDays(90)])))
        ->toThrow(QueryException::class, 'states the day it was issued');

    expect($invoice->fresh()->issued_at->toDateTimeString())->toBe($issued->toDateTimeString());
});

it('leaves the collection states moving in both directions', function () {
    [, $invoice] = vinInvoice();

    Meteric::recordPayment($invoice, Money::ofMinor($invoice->total_minor, 'EUR'));
    expect($invoice->fresh()->state)->toBe(InvoiceState::Paid);

    // A payment that is reversed or charged back returns the document to open,
    // which is the caller's lifecycle and not the trigger's.
    DB::table((new Invoice)->getTable())
        ->where('id', $invoice->id)
        ->update(['state' => InvoiceState::Open->value]);

    expect($invoice->fresh()->state)->toBe(InvoiceState::Open);
});

it('refuses to truncate the tables holding issued documents', function () {
    [, $invoice] = vinInvoice();

    $invoices = (new Invoice)->getTable();
    $lines = (new InvoiceLine)->getTable();

    // A row-level trigger does not fire on a TRUNCATE: one statement emptied
    // every issued document and the branch refusing a delete never ran.
    // CASCADE, because a plain TRUNCATE on either table is refused by the
    // foreign keys pointing at it and proves nothing about the trigger.
    expect(vinRefused(fn () => DB::statement("TRUNCATE TABLE {$lines} CASCADE")))
        ->toThrow(QueryException::class, 'is never truncated');

    expect(vinRefused(fn () => DB::statement("TRUNCATE TABLE {$invoices} CASCADE")))
        ->toThrow(QueryException::class, 'is never truncated');

    expect(Invoice::whereKey($invoice->id)->exists())->toBeTrue()
        ->and(InvoiceLine::where('invoice_id', $invoice->id)->exists())->toBeTrue();
});

it('allows a truncate that removes nothing', function () {
    // Truncating an empty table destroys no document, and refusing it would
    // cost a fresh install its fixtures and any seeder whose CASCADE reaches
    // these tables. The row guard beside this one refuses the delete, so a row
    // cannot be taken out of the way first.
    $invoices = (new Invoice)->getTable();

    expect(Invoice::count())->toBe(0);

    DB::transaction(fn () => DB::statement("TRUNCATE TABLE {$invoices} CASCADE"));

    vinInvoice();

    expect(vinRefused(fn () => DB::statement("TRUNCATE TABLE {$invoices} CASCADE")))
        ->toThrow(QueryException::class, 'is never truncated');
});
