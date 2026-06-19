<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Meteric\Models\BillingAccount;
use Meteric\Models\BillingPeriod;
use Meteric\Models\Price;
use Meteric\Models\Product;
use Meteric\Models\Subscription;
use Meteric\Models\SubscriptionItem;
use Meteric\Support\Period;

uses(RefreshDatabase::class);

function makeItem(): SubscriptionItem
{
    $account = BillingAccount::create(['owner_type' => 'user', 'owner_id' => '1', 'currency' => 'EUR']);
    $product = Product::create(['type' => 'vps', 'slug' => 'vps-'.uniqid(), 'name' => 'VPS', 'pricing_model' => 'fixed']);
    $price = Price::create([
        'product_id' => $product->id, 'currency' => 'EUR', 'amount_minor' => 1000,
        'pricing_model' => 'fixed', 'interval' => 'month', 'interval_count' => 1,
    ]);
    $sub = Subscription::create([
        'account_id' => $account->id, 'customer_type' => 'user', 'customer_id' => '1', 'currency' => 'EUR',
    ]);

    return SubscriptionItem::create([
        'subscription_id' => $sub->id, 'product_id' => $product->id, 'price_id' => $price->id, 'quantity' => 1,
    ]);
}

it('allows non-overlapping billed windows for an item', function () {
    $item = makeItem();

    BillingPeriod::create(['item_id' => $item->id, 'covers' => new Period(
        CarbonImmutable::parse('2026-06-01Z'), CarbonImmutable::parse('2026-07-01Z'),
    )]);
    BillingPeriod::create(['item_id' => $item->id, 'covers' => new Period(
        CarbonImmutable::parse('2026-07-01Z'), CarbonImmutable::parse('2026-08-01Z'),
    )]);

    expect(BillingPeriod::where('item_id', $item->id)->count())->toBe(2);
});

it('refuses to bill an overlapping window twice (GiST EXCLUDE)', function () {
    $item = makeItem();

    BillingPeriod::create(['item_id' => $item->id, 'covers' => new Period(
        CarbonImmutable::parse('2026-06-01Z'), CarbonImmutable::parse('2026-07-01Z'),
    )]);

    // Overlaps the June window → exclusion_violation.
    BillingPeriod::create(['item_id' => $item->id, 'covers' => new Period(
        CarbonImmutable::parse('2026-06-15Z'), CarbonImmutable::parse('2026-07-15Z'),
    )]);
})->throws(QueryException::class);

it('isolates the guard per dimension', function () {
    $item = makeItem();
    $same = new Period(CarbonImmutable::parse('2026-06-01Z'), CarbonImmutable::parse('2026-07-01Z'));

    // Same window, two different dimensions → both allowed.
    BillingPeriod::create(['item_id' => $item->id, 'dimension_id' => null, 'covers' => $same]);
    BillingPeriod::create(['item_id' => $item->id, 'dimension_id' => (string) Str::uuid(), 'covers' => $same]);

    expect(BillingPeriod::where('item_id', $item->id)->count())->toBe(2);
});
