<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Meteric\Enums\BillingMode;
use Meteric\Enums\LineKind;
use Meteric\Facades\Meteric;
use Meteric\Models\BillingAccount;
use Meteric\Models\Charge;
use Meteric\Models\MeterDimension;
use Meteric\Models\Price;
use Meteric\Models\Product;
use Meteric\Models\Subscription;
use Meteric\Models\SubscriptionItem;
use Meteric\Models\UsageRecord;
use Meteric\Support\Period;

uses(RefreshDatabase::class);

function meteredItem(string $rate = '0.500000', float $included = 0): SubscriptionItem
{
    $account = BillingAccount::create(['owner_type' => 'user', 'owner_id' => '1', 'currency' => 'EUR']);
    $product = Product::create(['type' => 'cloud', 'slug' => 'cloud-'.uniqid(), 'name' => 'Cloud', 'pricing_model' => 'metered']);
    MeterDimension::create([
        'product_id' => $product->id, 'key' => 'traffic', 'unit' => 'GB',
        'aggregation' => 'sum', 'rate' => $rate, 'currency' => 'EUR', 'included_qty' => $included,
    ]);
    $price = Price::create([
        'product_id' => $product->id, 'currency' => 'EUR', 'amount_minor' => 0,
        'pricing_model' => 'metered', 'interval' => 'month', 'interval_count' => 1, 'billing_mode' => 'in_arrears',
    ]);
    $sub = Subscription::create(['account_id' => $account->id, 'customer_type' => 'user', 'customer_id' => '1', 'currency' => 'EUR']);

    return SubscriptionItem::create([
        'subscription_id' => $sub->id, 'product_id' => $product->id, 'price_id' => $price->id, 'quantity' => 1,
    ]);
}

function usageWindow(): Period
{
    return new Period(CarbonImmutable::parse('2026-06-01Z'), CarbonImmutable::parse('2026-07-01Z'));
}

it('rolls up reported usage into an in-arrears charge', function () {
    $item = meteredItem('0.500000');

    Meteric::recordUsage($item, 'traffic', 60, CarbonImmutable::parse('2026-06-10Z'));
    Meteric::recordUsage($item, 'traffic', 40, CarbonImmutable::parse('2026-06-20Z'));

    $charges = Meteric::rollupUsage($item, usageWindow());

    expect($charges)->toHaveCount(1);
    $charge = $charges[0];
    // 100 GB × €0.50 = €50.00
    expect($charge->amount_minor)->toBe(5000)
        ->and($charge->kind)->toBe(LineKind::Usage)
        ->and($charge->billing_mode)->toBe(BillingMode::InArrears);

    // records stamped as billed
    expect(UsageRecord::where('item_id', $item->id)->whereNull('charge_id')->count())->toBe(0);
});

it('subtracts the included allowance', function () {
    $item = meteredItem('0.100000', included: 20); // 20 GB free

    Meteric::recordUsage($item, 'traffic', 120, CarbonImmutable::parse('2026-06-10Z'));
    $charges = Meteric::rollupUsage($item, usageWindow());

    // (120 - 20) × €0.10 = €10.00
    expect($charges[0]->amount_minor)->toBe(1000);
});

it('is idempotent — rolling up the same window again bills nothing', function () {
    $item = meteredItem('0.500000');
    Meteric::recordUsage($item, 'traffic', 100, CarbonImmutable::parse('2026-06-10Z'));

    Meteric::rollupUsage($item, usageWindow());
    $again = Meteric::rollupUsage($item, usageWindow());

    expect($again)->toHaveCount(0)
        ->and(Charge::where('subscription_id', $item->subscription_id)->count())->toBe(1);
});

it('record is idempotent on the supplied key', function () {
    $item = meteredItem();

    Meteric::recordUsage($item, 'traffic', 10, null, key: 'evt-1');
    Meteric::recordUsage($item, 'traffic', 10, null, key: 'evt-1');

    expect(UsageRecord::where('item_id', $item->id)->count())->toBe(1);
});
