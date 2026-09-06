<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Meteric\Enums\ChargeState;
use Meteric\Enums\InvoiceSchedule;
use Meteric\Enums\LineKind;
use Meteric\Facades\Meteric;
use Meteric\Invoicing\CollectionCycle;
use Meteric\Models\BillingAccount;
use Meteric\Models\Charge;
use Meteric\Models\Invoice;
use Meteric\Models\Price;
use Meteric\Models\Product;

uses(RefreshDatabase::class);

function collective(): BillingAccount
{
    return BillingAccount::create(['owner_type' => 'user', 'owner_id' => (string) Str::uuid(), 'currency' => 'EUR']);
}

function accrue(BillingAccount $acc, int $minor, string $title, string $currency = 'EUR'): Charge
{
    return Charge::create([
        'account_id' => $acc->id,
        'origin_type' => 'manual', 'origin_id' => (string) Str::uuid(),
        'kind' => LineKind::OneOff, 'billing_mode' => 'in_advance',
        'state' => ChargeState::Pending, 'title' => $title, 'description' => $title,
        'quantity' => 1, 'unit_minor' => $minor, 'amount_minor' => $minor,
        'currency' => $currency, 'idempotency_key' => (string) Str::uuid(),
    ]);
}

it('defaults an account to invoicing per event', function () {
    $account = collective();

    expect($account->invoice_schedule)->toBe(InvoiceSchedule::Immediate)
        ->and($account->defersInvoicing())->toBeFalse();

    accrue($account, 1000, 'VPS');

    expect(Meteric::invoicePending($account))->not->toBeNull();
});

it('leaves a collective account\'s charges pending instead of invoicing them', function () {
    $account = Meteric::setInvoiceSchedule(collective(), InvoiceSchedule::Collective);

    accrue($account, 1000, 'VPS');
    accrue($account, 250, 'Backups');

    expect(Meteric::invoicePending($account))->toBeNull()
        ->and(Meteric::invoiceAllPending($account))->toBe([])
        ->and(Charge::where('account_id', $account->id)->pending()->count())->toBe(2);
});

it('bills anyway when the caller forces it', function () {
    $account = Meteric::setInvoiceSchedule(collective(), InvoiceSchedule::Collective);
    accrue($account, 1000, 'VPS');

    $invoice = Meteric::invoicePending($account, force: true);

    expect($invoice)->not->toBeNull()->and($invoice->subtotal_minor)->toBe(1000);
});

it('issues one invoice on the collection date for everything the cycle accrued', function () {
    $account = Meteric::setInvoiceSchedule(collective(), InvoiceSchedule::Collective, at: CarbonImmutable::parse('2026-03-04'));

    accrue($account, 1000, 'VPS');
    accrue($account, 250, 'Backups');
    accrue($account, 500, 'Domain');

    // Still inside the cycle: nothing is due.
    expect(Meteric::invoiceCollective($account, CarbonImmutable::parse('2026-03-20')))->toBe([]);

    $invoices = Meteric::invoiceCollective($account->refresh(), CarbonImmutable::parse('2026-04-01 03:00'));

    expect($invoices)->toHaveCount(1)
        ->and($invoices[0]->subtotal_minor)->toBe(1750)
        ->and($invoices[0]->lines)->toHaveCount(3)
        ->and($invoices[0]->lines->pluck('title')->all())->toEqualCanonicalizing(['VPS', 'Backups', 'Domain'])
        ->and(Charge::where('account_id', $account->id)->pending()->count())->toBe(0)
        ->and($account->refresh()->collected_through->toDateString())->toBe('2026-04-01');
});

it('bills a cycle once however often the run repeats', function () {
    $account = Meteric::setInvoiceSchedule(collective(), InvoiceSchedule::Collective, at: CarbonImmutable::parse('2026-03-04'));
    accrue($account, 1000, 'VPS');

    $first = Meteric::invoiceCollective($account, CarbonImmutable::parse('2026-04-01 03:00'));
    accrue($account->refresh(), 400, 'Later that day');
    $second = Meteric::invoiceCollective($account->refresh(), CarbonImmutable::parse('2026-04-01 09:00'));

    expect($first)->toHaveCount(1)
        ->and($second)->toBe([])
        ->and(Charge::where('account_id', $account->id)->pending()->count())->toBe(1);
});

it('bills a missed cycle on the next run rather than losing or doubling it', function () {
    $account = Meteric::setInvoiceSchedule(collective(), InvoiceSchedule::Collective, at: CarbonImmutable::parse('2026-03-04'));
    accrue($account, 1000, 'VPS');

    // Nothing ran on 1 April; the run on the 9th bills that cycle, once.
    $invoices = Meteric::invoiceCollective($account, CarbonImmutable::parse('2026-04-09'));

    expect($invoices)->toHaveCount(1)
        ->and($account->refresh()->collected_through->toDateString())->toBe('2026-04-01');

    expect(Meteric::invoiceCollective($account, CarbonImmutable::parse('2026-04-10')))->toBe([]);
});

it('does not bill the cycle an account opted in part way through', function () {
    $account = Meteric::setInvoiceSchedule(collective(), InvoiceSchedule::Collective, at: CarbonImmutable::parse('2026-03-15'));

    expect($account->collected_through->toDateString())->toBe('2026-03-01')
        ->and($account->isDueForCollection(CarbonImmutable::parse('2026-03-16')))->toBeFalse()
        ->and($account->isDueForCollection(CarbonImmutable::parse('2026-04-01')))->toBeTrue();
});

it('bills each currency onto its own invoice', function () {
    $account = Meteric::setInvoiceSchedule(collective(), InvoiceSchedule::Collective, at: CarbonImmutable::parse('2026-03-04'));
    accrue($account, 1000, 'VPS', 'EUR');
    accrue($account, 900, 'Support', 'CHF');

    $invoices = Meteric::invoiceCollective($account, CarbonImmutable::parse('2026-04-01'));

    expect($invoices)->toHaveCount(2)
        ->and(collect($invoices)->pluck('currency')->all())->toEqualCanonicalizing(['EUR', 'CHF']);
});

it('honours a day of its own and clamps it to a short month', function () {
    $account = Meteric::setInvoiceSchedule(collective(), InvoiceSchedule::Collective, day: 31, at: CarbonImmutable::parse('2026-01-31'));

    expect($account->collected_through->toDateString())->toBe('2026-01-31')
        ->and($account->nextCollectionAt(CarbonImmutable::parse('2026-02-01'))->toDateString())->toBe('2026-02-28')
        ->and($account->isDueForCollection(CarbonImmutable::parse('2026-02-28')))->toBeTrue();
});

it('refuses a day outside the month', function () {
    Meteric::setInvoiceSchedule(collective(), InvoiceSchedule::Collective, day: 32);
})->throws(InvalidArgumentException::class);

it('clears the schedule and the stamp when an account goes back to per event', function () {
    $account = Meteric::setInvoiceSchedule(collective(), InvoiceSchedule::Collective, day: 15);
    accrue($account, 1000, 'VPS');

    $account = Meteric::setInvoiceSchedule($account, InvoiceSchedule::Immediate);

    expect($account->invoice_schedule)->toBe(InvoiceSchedule::Immediate)
        ->and($account->invoice_day)->toBeNull()
        ->and($account->collected_through)->toBeNull()
        ->and($account->isDueForCollection())->toBeFalse();

    // The pool that accrued while it deferred is billable again straight away.
    expect(Meteric::invoicePending($account)?->subtotal_minor)->toBe(1000);
});

it('lists only the accounts a run should look at', function () {
    $immediate = collective();
    accrue($immediate, 100, 'VPS');

    $due = Meteric::setInvoiceSchedule(collective(), InvoiceSchedule::Collective, at: CarbonImmutable::parse('2026-03-04'));
    $fresh = Meteric::setInvoiceSchedule(collective(), InvoiceSchedule::Collective, at: CarbonImmutable::parse('2026-04-01'));

    $ids = Meteric::dueForCollection(CarbonImmutable::parse('2026-04-01 06:00'))->pluck('id')->all();

    expect($ids)->toContain($due->id)
        ->and($ids)->not->toContain($fresh->id)
        ->and($ids)->not->toContain($immediate->id);
});

it('walks the month boundary in both directions', function () {
    $at = CarbonImmutable::parse('2026-05-17 12:00');

    expect(CollectionCycle::boundary($at, 1)->toDateString())->toBe('2026-05-01')
        ->and(CollectionCycle::boundary($at, 20)->toDateString())->toBe('2026-04-20')
        ->and(CollectionCycle::next($at, 1)->toDateString())->toBe('2026-06-01')
        ->and(CollectionCycle::next($at, 20)->toDateString())->toBe('2026-05-20')
        ->and(CollectionCycle::boundary(CarbonImmutable::parse('2026-03-05'), 31)->toDateString())->toBe('2026-02-28');
});

it('renews a collective account through the tick without invoicing, then bills the cycle', function () {
    $account = Meteric::setInvoiceSchedule(collective(), InvoiceSchedule::Collective, at: CarbonImmutable::parse('2026-06-01Z'));

    $product = Product::create(['type' => 'vps', 'slug' => 'coll-'.uniqid(), 'name' => 'VPS', 'pricing_model' => 'fixed']);
    $price = Price::create([
        'product_id' => $product->id, 'currency' => 'EUR', 'amount_minor' => 1000,
        'pricing_model' => 'fixed', 'interval' => 'month', 'interval_count' => 1,
    ]);
    Meteric::subscribe()->account($account)->at(CarbonImmutable::parse('2026-06-01Z'))->add($price, 1)->create();

    // Mid-cycle: the renewal accrues, nothing is invoiced.
    test()->travelTo(CarbonImmutable::parse('2026-06-20T06:00:00Z'));
    test()->artisan('meteric:run')->assertSuccessful();

    expect(Invoice::where('account_id', $account->id)->count())->toBe(0)
        ->and(Charge::where('account_id', $account->id)->pending()->count())->toBe(1);

    // The boundary: one document for the whole cycle, and only one.
    test()->travelTo(CarbonImmutable::parse('2026-07-01T06:00:00Z'));
    test()->artisan('meteric:run')->assertSuccessful();
    test()->artisan('meteric:run')->assertSuccessful();

    $invoices = Invoice::where('account_id', $account->id)->get();

    expect($invoices)->toHaveCount(1)
        ->and($invoices->first()->subtotal_minor)->toBe(2000)
        ->and($invoices->first()->lines)->toHaveCount(2);
});

it('starts payment terms from the issue date, not the accrual date', function () {
    config()->set('meteric.invoice.net_days', 14);
    $account = Meteric::setInvoiceSchedule(collective(), InvoiceSchedule::Collective, at: CarbonImmutable::parse('2026-03-01'));

    test()->travelTo(CarbonImmutable::parse('2026-03-03T09:00:00Z'));
    accrue($account, 1000, 'VPS');

    test()->travelTo(CarbonImmutable::parse('2026-04-01T06:00:00Z'));
    $invoice = Meteric::invoiceCollective($account->refresh(), CarbonImmutable::parse('2026-04-01T06:00:00Z'))[0];

    expect($invoice->issued_at->toDateString())->toBe('2026-04-01')
        ->and($invoice->due_at->toDateString())->toBe('2026-04-15')
        ->and($invoice->isOverdue())->toBeFalse();

    test()->travelTo(CarbonImmutable::parse('2026-04-16T06:00:00Z'));
    expect(Meteric::markOverdue())->toBe(1);
});
