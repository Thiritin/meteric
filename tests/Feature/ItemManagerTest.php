<?php

declare(strict_types=1);

use Billify\Enums\ItemState;
use Billify\Enums\LineKind;
use Billify\Facades\Billify;
use Billify\Models\Addon;
use Billify\Models\BillingAccount;
use Billify\Models\Charge;
use Billify\Models\Price;
use Billify\Models\Product;
use Billify\Models\SubscriptionItem;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function priced(int $minor, string $model = 'fixed'): Price
{
    $product = Product::create(['type' => 'vps', 'slug' => 's-'.uniqid(), 'name' => 'P', 'pricing_model' => $model]);

    return Price::create([
        'product_id' => $product->id, 'currency' => 'EUR', 'amount_minor' => $minor,
        'pricing_model' => $model, 'interval' => 'month', 'interval_count' => 1,
    ]);
}

/** Active item mid-June (15 of 30 days remaining). */
function midCycleItem(): SubscriptionItem
{
    $acc = BillingAccount::create(['owner_type' => 'user', 'owner_id' => '1', 'currency' => 'EUR']);
    $sub = Billify::subscribe()->account($acc)
        ->at(CarbonImmutable::parse('2026-06-01T00:00:00Z'))
        ->add(priced(3000), 1)
        ->create();
    $item = $sub->items->first();
    $item->setRelation('subscription', $sub);

    return $item;
}

function changeAt(): CarbonImmutable
{
    return CarbonImmutable::parse('2026-06-16T00:00:00Z'); // 15/30 days left
}

it('prorates an addon booked mid-cycle', function () {
    $item = midCycleItem();
    $ram = priced(1000);

    Billify::addAddon($item, $ram, group: 'ram', qty: 1, at: changeAt());

    $addonCharge = Charge::where('origin_type', 'addon')->where('kind', LineKind::Addon->value)->first();
    expect($addonCharge->amount_minor)->toBe(500); // 1000 * 15/30
});

it('swaps an addon within a group (credit old + charge new)', function () {
    $item = midCycleItem();
    Billify::addAddon($item, priced(1000), group: 'ram', qty: 1, at: changeAt());
    Billify::addAddon($item, priced(2000), group: 'ram', qty: 1, at: changeAt());

    // only one active addon in the group
    expect(Addon::where('item_id', $item->id)->where('state', ItemState::Active->value)->count())->toBe(1);
    // credit for the removed +1000 (−500) and charge for the new +2000 (1000)
    expect(Charge::where('kind', LineKind::Credit->value)->where('origin_type', 'addon')->exists())->toBeTrue();
});

it('prorates a configurable option (per-unit slots)', function () {
    $item = midCycleItem();
    $slot = priced(30, 'per_unit'); // €0.30/slot

    Billify::setOption($item, 'slots', '8', 'quantity', price: $slot, qty: 8, at: changeAt());

    // 8 × €0.30 = €2.40, prorated 15/30 = €1.20
    $optCharge = Charge::where('origin_type', 'item_option')->first();
    expect($optCharge->amount_minor)->toBe(120);
});

it('prorates a base quantity increase', function () {
    $item = midCycleItem();

    Billify::setQuantity($item, 3, changeAt()); // +2 × €30 = €60, prorated €30

    $qtyCharge = Charge::where('description', 'Quantity change')->first();
    expect($qtyCharge->amount_minor)->toBe(3000)
        ->and($item->fresh()->quantity)->toBe(3.0);
});
