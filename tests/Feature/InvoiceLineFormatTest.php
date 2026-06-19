<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Meteric\Enums\LineKind;
use Meteric\Facades\Meteric;
use Meteric\Models\BillingAccount;
use Meteric\Models\MeterDimension;
use Meteric\Models\Price;
use Meteric\Models\Product;
use Meteric\Models\Subscription;
use Meteric\Models\SubscriptionItem;
use Meteric\Support\Period;

uses(RefreshDatabase::class);

function lineAccount(): BillingAccount
{
    return BillingAccount::create([
        'owner_type' => 'user', 'owner_id' => '1', 'currency' => 'EUR',
        'tax_profile' => ['country' => 'US', 'merchant_country' => 'DE'],
    ]);
}

it('titles a recurring line by the item label with a month unit and the period', function () {
    $acc = lineAccount();
    $product = Product::create(['type' => 'vps', 'slug' => 'l-'.uniqid(), 'name' => 'VPS XL', 'pricing_model' => 'fixed']);
    $price = Price::create([
        'product_id' => $product->id, 'currency' => 'EUR', 'amount_minor' => 1000,
        'pricing_model' => 'fixed', 'interval' => 'month', 'interval_count' => 1,
    ]);

    Meteric::subscribe()->account($acc)
        ->at(CarbonImmutable::parse('2026-06-01Z'))
        ->add($price, 1, null, label: 'vps12345.example')
        ->create();
    $line = Meteric::invoicePending($acc)->lines->first();

    expect($line->description)->toBe('vps12345.example')   // the title is the hostname, not "VPS XL"
        ->and($line->unit)->toBe('month')
        ->and($line->coversLabel())->toBe('2026-06-01 to 2026-07-01');
});

it('summarises a usage line with the total used and its unit', function () {
    $acc = lineAccount();
    $product = Product::create(['type' => 'cloud', 'slug' => 'lc-'.uniqid(), 'name' => 'Cloud', 'pricing_model' => 'metered']);
    MeterDimension::create(['product_id' => $product->id, 'key' => 'traffic', 'unit' => 'GB', 'aggregation' => 'sum', 'rate' => '0.010000', 'currency' => 'EUR', 'included_qty' => 0]);
    $price = Price::create(['product_id' => $product->id, 'currency' => 'EUR', 'amount_minor' => 0, 'pricing_model' => 'metered', 'interval' => 'month', 'interval_count' => 1, 'billing_mode' => 'in_arrears']);
    $sub = Subscription::create(['account_id' => $acc->id, 'customer_type' => 'user', 'customer_id' => '1', 'currency' => 'EUR']);
    $item = SubscriptionItem::create(['subscription_id' => $sub->id, 'product_id' => $product->id, 'price_id' => $price->id, 'quantity' => 1, 'label' => 'vm-7f3a.example']);

    Meteric::recordUsage($item, 'traffic', 800, CarbonImmutable::parse('2026-06-10Z'), key: 'a');
    Meteric::recordUsage($item, 'traffic', 700, CarbonImmutable::parse('2026-06-20Z'), key: 'b');
    Meteric::rollupUsage($item, new Period(CarbonImmutable::parse('2026-06-01Z'), CarbonImmutable::parse('2026-07-01Z')));

    $line = Meteric::invoicePending($acc)->lines->first();

    // One line summarising the VM's total traffic for the cycle.
    expect($line->kind)->toBe(LineKind::Usage)
        ->and($line->description)->toBe('vm-7f3a.example')
        ->and($line->unit)->toBe('GB')
        ->and($line->usedSummary())->toBe('1500 GB')           // total summed across the cycle
        ->and($line->metadata['dimension'])->toBe('traffic')
        ->and($line->amount_minor)->toBe(1500);                // 1500 × €0.01 = €15.00
});
