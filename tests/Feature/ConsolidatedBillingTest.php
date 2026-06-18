<?php

declare(strict_types=1);

use Billify\Enums\ChargeState;
use Billify\Enums\LineKind;
use Billify\Facades\Billify;
use Billify\Models\BillingAccount;
use Billify\Models\Charge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

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

    $invoice = Billify::invoiceConsolidated($payer);

    expect($invoice)->not->toBeNull()
        ->and($invoice->account_id)->toBe($payer->id)
        ->and($invoice->subtotal_minor)->toBe(3500)
        ->and($invoice->lines)->toHaveCount(3);

    // every charge across the org is now invoiced
    expect(Charge::whereIn('account_id', [$payer->id, $childA->id, $childB->id])->pending()->count())->toBe(0);
});

it('does not pull in unrelated accounts', function () {
    $payer = BillingAccount::create(['owner_type' => 'org', 'owner_id' => '1', 'currency' => 'EUR']);
    $other = BillingAccount::create(['owner_type' => 'org', 'owner_id' => '9', 'currency' => 'EUR']);
    pending($payer, 500, 'Mine');
    pending($other, 999, 'Theirs');

    $invoice = Billify::invoiceConsolidated($payer);

    expect($invoice->subtotal_minor)->toBe(500)
        ->and(Charge::where('account_id', $other->id)->pending()->count())->toBe(1);
});
