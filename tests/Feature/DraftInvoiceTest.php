<?php

declare(strict_types=1);

use Brick\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Meteric\Enums\ChargeState;
use Meteric\Enums\InvoiceState;
use Meteric\Enums\LineKind;
use Meteric\Events\InvoiceIssued;
use Meteric\Events\InvoiceOverdue;
use Meteric\Facades\Meteric;
use Meteric\Models\BillingAccount;
use Meteric\Models\Charge;
use Meteric\Models\InvoiceLine;

uses(RefreshDatabase::class);

function draftAccount(): BillingAccount
{
    return BillingAccount::create([
        'owner_type' => 'user', 'owner_id' => '1', 'currency' => 'EUR',
        'tax_profile' => ['country' => 'DE', 'merchant_country' => 'DE', 'name' => 'Acme GmbH'],
    ]);
}

function draftCharge(BillingAccount $account, int $amountMinor, string $title): Charge
{
    return Charge::create([
        'account_id' => $account->id,
        'origin_type' => 'manual', 'origin_id' => (string) Str::uuid(),
        'kind' => LineKind::Recurring, 'billing_mode' => 'in_advance',
        'state' => ChargeState::Pending, 'title' => $title, 'description' => $title,
        'quantity' => 1, 'unit' => 'month', 'unit_minor' => $amountMinor, 'amount_minor' => $amountMinor,
        'currency' => 'EUR', 'idempotency_key' => (string) Str::uuid(),
    ]);
}

/** Count an invoice's charges (those with a non-void line referencing them) in a given state. */
function chargesOf(string $invoiceId, ChargeState $state): int
{
    $ids = InvoiceLine::where('invoice_id', $invoiceId)->whereNotNull('charge_id')->pluck('charge_id')->unique();

    return Charge::whereIn('id', $ids)->where('state', $state->value)->count();
}

it('drafts an invoice from pending charges without issuing it', function () {
    Event::fake([InvoiceIssued::class]);

    $account = draftAccount();
    draftCharge($account, 1000, 'VPS S');
    draftCharge($account, 500, 'Backups');

    $draft = Meteric::draftInvoice($account);

    expect($draft->state)->toBe(InvoiceState::Draft)
        ->and($draft->due_at)->toBeNull()
        ->and($draft->lines()->count())->toBe(2)
        ->and($draft->subtotal_minor)->toBe(1500)
        ->and(chargesOf($draft->id, ChargeState::Invoiced))->toBe(2);

    Event::assertNotDispatched(InvoiceIssued::class);

    // The charges are reserved (invoiced via lines), so a later invoicePending finds nothing.
    expect(Meteric::invoicePending($account))->toBeNull();
});

it('drafts an empty invoice when nothing is pending', function () {
    $account = draftAccount();

    $draft = Meteric::draftInvoice($account);

    expect($draft->state)->toBe(InvoiceState::Draft)
        ->and($draft->lines()->count())->toBe(0)
        ->and($draft->subtotal_minor)->toBe(0)
        ->and($draft->total_minor)->toBe(0);
});

it('opens an empty draft with createInvoice and edits it by hand', function () {
    $account = draftAccount();

    $draft = Meteric::createInvoice($account);
    expect($draft->state)->toBe(InvoiceState::Draft)
        ->and($draft->lines()->count())->toBe(0);

    $line = Meteric::addLine($draft, 'Consulting', Money::ofMinor(5000, 'EUR'), 'October work');
    Meteric::addSubLine($line, 'Travel', Money::ofMinor(1500, 'EUR'));
    Meteric::addLine($draft, 'Support', Money::ofMinor(2000, 'EUR'));

    $draft->refresh();

    expect($draft->lines()->count())->toBe(3)
        ->and($draft->subtotal_minor)->toBe(8500)            // 5000 + 1500 + 2000, parent own-only + child + standalone
        ->and($draft->total_minor)->toBe($draft->subtotal_minor + $draft->tax_minor);

    // The sub-line nests under its parent.
    $sub = $draft->lines()->whereNotNull('parent_id')->first();
    expect($sub->parent_id)->toBe($line->id)
        ->and($sub->amount_minor)->toBe(1500);
});

it('removes a line and its sub-lines, recomputing totals', function () {
    $account = draftAccount();
    $draft = Meteric::createInvoice($account);

    $a = Meteric::addLine($draft, 'A', Money::ofMinor(1000, 'EUR'));
    Meteric::addSubLine($a, 'A child', Money::ofMinor(400, 'EUR'));
    Meteric::addLine($draft, 'B', Money::ofMinor(700, 'EUR'));

    expect($draft->fresh()->subtotal_minor)->toBe(2100);

    Meteric::removeLine($a);

    expect($draft->fresh()->lines()->count())->toBe(1)        // child cascaded away
        ->and($draft->fresh()->subtotal_minor)->toBe(700);
});

it('reverts a charge to pending when its only draft line is removed', function () {
    $account = draftAccount();
    $charge = draftCharge($account, 1200, 'VPS M');

    $draft = Meteric::draftInvoice($account);
    expect($charge->fresh()->state)->toBe(ChargeState::Invoiced);

    $line = $draft->lines()->whereNotNull('charge_id')->first();
    Meteric::removeLine($line);

    expect($charge->fresh()->state)->toBe(ChargeState::Pending);

    // Released, so a later invoicePending picks it up.
    $invoice = Meteric::invoicePending($account);
    expect($invoice)->not->toBeNull()
        ->and($invoice->subtotal_minor)->toBe(1200);
});

it('copies an invoice cloning its lines and sub-line hierarchy, keeping charge_id without duplicating charges', function () {
    $account = draftAccount();
    $draft = Meteric::createInvoice($account);

    $parent = Meteric::addLine($draft, 'VPS', Money::ofMinor(1000, 'EUR'));
    Meteric::addSubLine($parent, 'Slots', Money::ofMinor(300, 'EUR'));

    // Attach a charge to the parent line to prove charge_id carries over.
    $charge = draftCharge($account, 1000, 'VPS');
    $parent->forceFill(['charge_id' => $charge->id])->save();

    $copy = Meteric::copyInvoice($draft);

    expect($copy->state)->toBe(InvoiceState::Draft)
        ->and($copy->lines()->count())->toBe(2)
        ->and($copy->subtotal_minor)->toBe($draft->fresh()->subtotal_minor)
        ->and(Charge::count())->toBe(1);   // no charge duplicated

    $copyParent = $copy->lines()->whereNull('parent_id')->first();
    $copyChild = $copy->lines()->whereNotNull('parent_id')->first();

    expect($copyParent->charge_id)->toBe($charge->id)         // charge_id preserved
        ->and($copyChild->parent_id)->toBe($copyParent->id)   // hierarchy remapped, no orphan
        ->and($copyChild->amount_minor)->toBe(300);
});

it('re-issues a voided invoice onto a copy and tracks payment + overdue', function () {
    Event::fake([InvoiceIssued::class, InvoiceOverdue::class]);

    $account = draftAccount();
    draftCharge($account, 2000, 'VPS L');

    // The original invoice goes out with the wrong address.
    $source = Meteric::invoicePending($account);
    expect($source->state)->toBe(InvoiceState::Open);

    // Canonical re-issue: copy first (clones the lines + charge_id), then void.
    $copy = Meteric::copyInvoice($source);
    Meteric::voidInvoice($source);
    expect($source->fresh()->state)->toBe(InvoiceState::Void);

    $final = Meteric::finalizeInvoice($copy);

    expect($final->state)->toBe(InvoiceState::Open)
        ->and($final->due_at)->not->toBeNull()
        ->and($final->subtotal_minor)->toBe(2000)
        ->and(chargesOf($final->id, ChargeState::Invoiced))->toBe(1);
    Event::assertDispatched(InvoiceIssued::class);

    // The charge keeps a live line on the copy, so voiding the source did not revert it.
    expect(Meteric::invoicePending($account))->toBeNull();

    // Tracking works on the finalized copy: a partial payment, then overdue.
    Meteric::recordPayment($final, Money::ofMinor(500, 'EUR'));
    expect($final->fresh()->state)->toBe(InvoiceState::PartiallyPaid);

    Meteric::markOverdue(CarbonImmutable::parse($final->due_at)->addDay());
    expect($final->fresh()->overdue_at)->not->toBeNull();
    Event::assertDispatched(InvoiceOverdue::class);
});

it('refuses to add a line to a non-draft invoice', function () {
    $account = draftAccount();
    draftCharge($account, 1000, 'VPS S');
    $open = Meteric::invoicePending($account);

    expect(fn () => Meteric::addLine($open, 'X', Money::ofMinor(100, 'EUR')))->toThrow(LogicException::class);
});

it('refuses to finalize a non-draft invoice', function () {
    $account = draftAccount();
    draftCharge($account, 1000, 'VPS S');
    $open = Meteric::invoicePending($account);

    expect(fn () => Meteric::finalizeInvoice($open))->toThrow(LogicException::class);
});
