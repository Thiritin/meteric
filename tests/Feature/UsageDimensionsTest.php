<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Meteric\Facades\Meteric;
use Meteric\Models\BillingAccount;
use Meteric\Models\MeterDimension;
use Meteric\Models\Price;
use Meteric\Models\Product;
use Meteric\Models\Subscription;
use Meteric\Models\SubscriptionItem;
use Meteric\Support\Period;

uses(RefreshDatabase::class);

/** Cloud item with arbitrary metered dimensions. */
function cloudItem(array $dimensions): SubscriptionItem
{
    $acc = BillingAccount::create(['owner_type' => 'user', 'owner_id' => '1', 'currency' => 'EUR']);
    $product = Product::create(['type' => 'cloud', 'slug' => 'cl-'.uniqid(), 'name' => 'Cloud', 'pricing_model' => 'metered']);
    foreach ($dimensions as $d) {
        MeterDimension::create(array_merge([
            'product_id' => $product->id, 'unit' => 'u', 'aggregation' => 'sum',
            'currency' => 'EUR', 'included_qty' => 0,
        ], $d));
    }
    $price = Price::create([
        'product_id' => $product->id, 'currency' => 'EUR', 'amount_minor' => 0,
        'pricing_model' => 'metered', 'interval' => 'month', 'interval_count' => 1, 'billing_mode' => 'in_arrears',
    ]);
    $sub = Subscription::create(['account_id' => $acc->id, 'customer_type' => 'user', 'customer_id' => '1', 'currency' => 'EUR']);

    return SubscriptionItem::create(['subscription_id' => $sub->id, 'product_id' => $product->id, 'price_id' => $price->id, 'quantity' => 1]);
}

function dimWindow(): Period
{
    return new Period(CarbonImmutable::parse('2026-06-01Z'), CarbonImmutable::parse('2026-07-01Z'));
}

it('bills each metered dimension as its own line (AWS-style)', function () {
    $item = cloudItem([
        ['key' => 'cpu_hour', 'rate' => '0.010000'],
        ['key' => 'traffic_gb', 'rate' => '0.500000'],
    ]);

    Meteric::recordUsage($item, 'cpu_hour', 100, CarbonImmutable::parse('2026-06-10Z'));   // €1.00
    Meteric::recordUsage($item, 'traffic_gb', 10, CarbonImmutable::parse('2026-06-12Z'));  // €5.00

    $charges = Meteric::rollupUsage($item, dimWindow());

    expect($charges)->toHaveCount(2)
        ->and(collect($charges)->sum('amount_minor'))->toBe(100 + 500);
});

it('caps a dimension at its monthly cap (Hetzner-style)', function () {
    $item = cloudItem([
        ['key' => 'traffic_gb', 'rate' => '0.500000', 'cap_minor' => 1000], // cap €10
    ]);

    Meteric::recordUsage($item, 'traffic_gb', 100, CarbonImmutable::parse('2026-06-10Z')); // 100×0.5 = €50

    $charges = Meteric::rollupUsage($item, dimWindow());

    expect($charges[0]->amount_minor)->toBe(1000); // capped to €10
});
