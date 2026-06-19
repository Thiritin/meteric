<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Meteric\Facades\Meteric;
use Meteric\Models\BillingAccount;
use Meteric\Models\MeterDimension;
use Meteric\Models\Price;
use Meteric\Models\Product;
use Meteric\Models\SubscriptionItem;
use Meteric\Support\Period;

uses(RefreshDatabase::class);

function recurringProductPrice(int $minor): Price
{
    $p = Product::create(['type' => 'vps', 'slug' => 'r-'.uniqid(), 'name' => 'VPS', 'pricing_model' => 'fixed']);

    return Price::create([
        'product_id' => $p->id, 'currency' => 'EUR', 'amount_minor' => $minor,
        'pricing_model' => 'fixed', 'interval' => 'month', 'interval_count' => 1,
    ]);
}

function oneOffProductPrice(int $minor): Price
{
    $p = Product::create(['type' => 'setup', 'slug' => 'o-'.uniqid(), 'name' => 'Setup', 'pricing_model' => 'one_off']);

    return Price::create([
        'product_id' => $p->id, 'currency' => 'EUR', 'amount_minor' => $minor,
        'pricing_model' => 'one_off', 'purpose' => 'setup',
    ]);
}

it('combines recurring, one-off and usage charges on one invoice', function () {
    $acc = BillingAccount::create(['owner_type' => 'user', 'owner_id' => '1', 'currency' => 'EUR']);

    // Recurring €10 + one-off setup €5 in one subscription.
    $sub = Meteric::subscribe()->account($acc)
        ->at(CarbonImmutable::parse('2026-06-01T00:00:00Z'))
        ->add(recurringProductPrice(1000), 1)
        ->add(oneOffProductPrice(500), 1)
        ->create();

    // A metered item with reported usage, rolled up in arrears.
    $cloud = Product::create(['type' => 'cloud', 'slug' => 'c-'.uniqid(), 'name' => 'Cloud', 'pricing_model' => 'metered']);
    MeterDimension::create(['product_id' => $cloud->id, 'key' => 'traffic', 'unit' => 'GB', 'aggregation' => 'sum', 'rate' => '0.500000', 'currency' => 'EUR', 'included_qty' => 0]);
    $cloudPrice = Price::create(['product_id' => $cloud->id, 'currency' => 'EUR', 'amount_minor' => 0, 'pricing_model' => 'metered', 'interval' => 'month', 'interval_count' => 1, 'billing_mode' => 'in_arrears']);
    $cloudItem = SubscriptionItem::create(['subscription_id' => $sub->id, 'product_id' => $cloud->id, 'price_id' => $cloudPrice->id, 'quantity' => 1]);

    Meteric::recordUsage($cloudItem, 'traffic', 20, CarbonImmutable::parse('2026-06-10Z')); // €10
    Meteric::rollupUsage($cloudItem, new Period(CarbonImmutable::parse('2026-06-01Z'), CarbonImmutable::parse('2026-07-01Z')));

    $invoice = Meteric::invoicePending($acc);

    // €10 recurring + €5 one-off + €10 usage = €25
    expect($invoice->lines)->toHaveCount(3)
        ->and($invoice->subtotal_minor)->toBe(2500)
        ->and($invoice->lines->pluck('kind')->map->value->sort()->values()->all())
        ->toBe(['one_off', 'recurring', 'usage']);
});
