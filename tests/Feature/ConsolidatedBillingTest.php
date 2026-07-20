<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Meteric\Enums\ChargeState;
use Meteric\Enums\LineKind;
use Meteric\Facades\Meteric;
use Meteric\Models\BillingAccount;
use Meteric\Models\Charge;

uses(RefreshDatabase::class);

function pending(BillingAccount $acc, int $minor, string $desc): Charge
{
    return Charge::create([
        'account_id' => $acc->id,
        'origin_type' => 'manual', 'origin_id' => (string) Str::uuid(),
        'kind' => LineKind::OneOff, 'billing_mode' => 'in_advance',
        'state' => ChargeState::Pending, 'description' => $desc,
        'quantity' => 1, 'unit_minor' => $minor, 'amount_minor' => $minor,
        'currency' => 'EUR', 'idempotency_key' => (string) Str::uuid(),
    ]);
}

it('bills payer + child accounts onto one consolidated invoice', function () {
    $payer = BillingAccount::create(['owner_type' => 'org', 'owner_id' => '1', 'currency' => 'EUR']);
    $childA = BillingAccount::create(['owner_type' => 'user', 'owner_id' => '2', 'currency' => 'EUR', 'parent_id' => $payer->id]);
    $childB = BillingAccount::create(['owner_type' => 'user', 'owner_id' => '3', 'currency' => 'EUR', 'parent_id' => $payer->id]);

    pending($payer, 500, 'Payer fee');
    pending($childA, 1000, 'Account A VPS');
    pending($childB, 2000, 'Account B VPS');

    $invoice = Meteric::invoiceConsolidated($payer);

    expect($invoice)->not->toBeNull()
        ->and($invoice->account_id)->toBe($payer->id)
        ->and($invoice->subtotal_minor)->toBe(3500)
        ->and($invoice->lines)->toHaveCount(3);

    // every charge across the org is now invoiced
    expect(Charge::whereIn('account_id', [$payer->id, $childA->id, $childB->id])->pending()->count())->toBe(0);
});

it('bills grandchild accounts, not just direct children', function () {
    $payer = BillingAccount::create(['owner_type' => 'org', 'owner_id' => '1', 'currency' => 'EUR']);
    $child = BillingAccount::create(['owner_type' => 'org', 'owner_id' => '2', 'currency' => 'EUR', 'parent_id' => $payer->id]);
    $grandchild = BillingAccount::create(['owner_type' => 'user', 'owner_id' => '3', 'currency' => 'EUR', 'parent_id' => $child->id]);

    pending($payer, 500, 'Payer fee');
    pending($child, 1000, 'Reseller fee');
    pending($grandchild, 2000, 'End customer VPS');

    $invoice = Meteric::invoiceConsolidated($payer);

    expect($invoice->subtotal_minor)->toBe(3500)
        ->and($invoice->lines)->toHaveCount(3)
        ->and(Charge::where('account_id', $grandchild->id)->pending()->count())->toBe(0);
});

it('does not pull in unrelated accounts', function () {
    $payer = BillingAccount::create(['owner_type' => 'org', 'owner_id' => '1', 'currency' => 'EUR']);
    $other = BillingAccount::create(['owner_type' => 'org', 'owner_id' => '9', 'currency' => 'EUR']);
    pending($payer, 500, 'Mine');
    pending($other, 999, 'Theirs');

    $invoice = Meteric::invoiceConsolidated($payer);

    expect($invoice->subtotal_minor)->toBe(500)
        ->and(Charge::where('account_id', $other->id)->pending()->count())->toBe(1);
});
