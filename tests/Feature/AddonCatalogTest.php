<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Meteric\Enums\FirstPeriodPolicy;
use Meteric\Enums\OptionType;
use Meteric\Facades\Meteric;
use Meteric\Models\BillingAccount;
use Meteric\Models\Price;
use Meteric\Models\Product;
use Meteric\Models\ProductAddon;
use Meteric\Models\ProductOption;
use Meteric\Models\ProductOptionValue;

uses(RefreshDatabase::class);

function catProduct(string $type = 'vps'): Product
{
    return Product::create(['type' => $type, 'slug' => $type.'-'.uniqid(), 'name' => ucfirst($type), 'pricing_model' => 'fixed']);
}

function catPrice(Product $product, int $minor, string $interval = 'month', int $count = 1, array $extra = []): Price
{
    return Price::create([
        'product_id' => $product->id, 'currency' => 'EUR', 'amount_minor' => $minor,
        'pricing_model' => 'fixed', 'interval' => $interval, 'interval_count' => $count,
        ...$extra,
    ]);
}

it('lists the bookable addons priced on the chosen term', function () {
    $vps = catProduct();
    $monthly = catPrice($vps, 1000);
    $yearly = catPrice($vps, 10000, 'year');

    $ip = catProduct('ipv4');
    catPrice($ip, 200);
    catPrice($ip, 2000, 'year');
    $backup = catProduct('backup');
    catPrice($backup, 400, extra: ['setup_fee_minor' => 100]);

    ProductAddon::create(['product_id' => $vps->id, 'addon_product_id' => $backup->id, 'group_key' => 'backup', 'required' => true, 'sort' => 2]);
    ProductAddon::create(['product_id' => $vps->id, 'addon_product_id' => $ip->id, 'min_qty' => 0, 'max_qty' => 8, 'sort' => 1]);

    $onMonthly = $vps->addonCatalog($monthly, qty: 2);
    $onYearly = $vps->addonCatalog($yearly);

    expect($onMonthly)->toHaveCount(2)
        ->and($onMonthly[0]['slug'])->toBe($ip->slug)
        ->and($onMonthly[0]['label'])->toBe('Ipv4')
        ->and($onMonthly[0]['required'])->toBeFalse()
        ->and($onMonthly[0]['min'])->toBe(0.0)
        ->and($onMonthly[0]['max'])->toBe(8.0)
        ->and($onMonthly[0]['amount_minor'])->toBe(400)
        ->and($onMonthly[0]['interval'])->toBe('month')
        ->and($onMonthly[1]['slug'])->toBe($backup->slug)
        ->and($onMonthly[1]['required'])->toBeTrue()
        ->and($onMonthly[1]['group_key'])->toBe('backup')
        ->and($onMonthly[1]['setup_fee_minor'])->toBe(100);

    // Backups have no yearly price, so they are not bookable on the yearly term.
    expect($onYearly)->toHaveCount(1)
        ->and($onYearly[0]['slug'])->toBe($ip->slug)
        ->and($onYearly[0]['amount_minor'])->toBe(2000)
        ->and($onYearly[0]['interval'])->toBe('year');
});

it('prefers an addon-purpose price and prices a relative addon against the base', function () {
    $vps = catProduct();
    $monthly = catPrice($vps, 1000);

    $ram = catProduct('ram');
    catPrice($ram, 600);
    $addonPrice = catPrice($ram, 500, extra: ['purpose' => 'addon']);
    $backups = catProduct('backup');
    Price::create(['product_id' => $backups->id, 'currency' => 'EUR', 'amount_minor' => 0, 'pricing_model' => 'relative', 'percent' => 20, 'interval' => 'month', 'interval_count' => 1]);

    $ramAddon = ProductAddon::create(['product_id' => $vps->id, 'addon_product_id' => $ram->id]);
    ProductAddon::create(['product_id' => $vps->id, 'addon_product_id' => $backups->id, 'sort' => 1]);

    $catalog = $vps->addonCatalog($monthly);

    expect($ramAddon->priceFor($monthly)->id)->toBe($addonPrice->id)
        ->and($catalog[0]['price_id'])->toBe($addonPrice->id)
        ->and($catalog[0]['amount_minor'])->toBe(500)
        ->and($catalog[1]['pricing_model'])->toBe('relative')
        ->and($catalog[1]['percent'])->toBe(20.0)
        ->and($catalog[1]['amount_minor'])->toBe(200);
});

it('refuses a term that belongs to another product', function () {
    $vps = catProduct();
    $other = catPrice(catProduct('other'), 1000);

    $vps->addonCatalog($other);
})->throws(InvalidArgumentException::class);

it('books a catalog addon on the order line with its group and term price', function () {
    test()->travelTo(CarbonImmutable::parse('2026-06-01T00:00:00Z'));
    $acc = BillingAccount::create(['owner_type' => 'user', 'owner_id' => '1', 'currency' => 'EUR']);

    $vps = catProduct();
    $yearly = catPrice($vps, 10000, 'year');
    $backup = catProduct('backup');
    catPrice($backup, 400);
    $backupYearly = catPrice($backup, 4000, 'year');
    $addon = ProductAddon::create(['product_id' => $vps->id, 'addon_product_id' => $backup->id, 'group_key' => 'backup', 'min_qty' => 1, 'max_qty' => 3]);

    $order = Meteric::createOrder()
        ->account($acc)
        ->firstPeriod(FirstPeriodPolicy::FullPeriod)
        ->add($yearly, 1, label: 'web1')
        ->bookAddon($addon, 2)
        ->create();

    $frozen = $order->contents[0]['addons'][0];
    expect($frozen['price_id'])->toBe($backupYearly->id)
        ->and($frozen['group_key'])->toBe('backup')
        ->and($frozen['quantity'])->toEqual(2)
        ->and($frozen['amount_minor'])->toBe(8000)
        ->and($order->subtotal_minor)->toBe(18000);
});

it('rejects a catalog addon outside its bounds or off its product', function () {
    $acc = BillingAccount::create(['owner_type' => 'user', 'owner_id' => '1', 'currency' => 'EUR']);
    $vps = catProduct();
    $monthly = catPrice($vps, 1000);
    $ip = catProduct('ipv4');
    catPrice($ip, 200);
    $addon = ProductAddon::create(['product_id' => $vps->id, 'addon_product_id' => $ip->id, 'max_qty' => 4]);
    $foreign = ProductAddon::create(['product_id' => catProduct('other')->id, 'addon_product_id' => $ip->id]);

    expect(fn () => Meteric::createOrder()->account($acc)->add($monthly)->bookAddon($addon, 5))
        ->toThrow(InvalidArgumentException::class, 'above the maximum');
    expect(fn () => Meteric::createOrder()->account($acc)->add($monthly)->bookAddon($foreign))
        ->toThrow(InvalidArgumentException::class, 'not offered');
});

it('rejects a catalog addon with no price on the term', function () {
    $acc = BillingAccount::create(['owner_type' => 'user', 'owner_id' => '1', 'currency' => 'EUR']);
    $vps = catProduct();
    $yearly = catPrice($vps, 10000, 'year');
    $ip = catProduct('ipv4');
    catPrice($ip, 200);
    $addon = ProductAddon::create(['product_id' => $vps->id, 'addon_product_id' => $ip->id]);

    Meteric::createOrder()->account($acc)->add($yearly)->bookAddon($addon);
})->throws(InvalidArgumentException::class, 'no EUR price');

it('selects a catalog option value on the order line and checks its bounds', function () {
    test()->travelTo(CarbonImmutable::parse('2026-06-01T00:00:00Z'));
    $acc = BillingAccount::create(['owner_type' => 'user', 'owner_id' => '1', 'currency' => 'EUR']);

    $vps = catProduct();
    $monthly = catPrice($vps, 1000);
    $ipPrice = catPrice($vps, 200, extra: ['purpose' => 'option', 'setup_fee_minor' => 300]);
    $option = ProductOption::create(['product_id' => $vps->id, 'key' => 'ips', 'label' => 'Extra IPs', 'type' => OptionType::Quantity->value, 'min_qty' => 1, 'max_qty' => 8]);
    $value = ProductOptionValue::create(['option_id' => $option->id, 'value' => 'ipv4', 'label' => 'IPv4', 'price_id' => $ipPrice->id]);

    $order = Meteric::createOrder()
        ->account($acc)
        ->firstPeriod(FirstPeriodPolicy::FullPeriod)
        ->add($monthly, 1, label: 'web1')
        ->chooseOption($value, 3)
        ->create();

    $frozen = $order->contents[0]['options'][0];
    expect($frozen['key'])->toBe('ips')
        ->and($frozen['value'])->toBe('ipv4')
        ->and($frozen['label'])->toBe('IPv4')
        ->and($frozen['type'])->toBe('quantity')
        ->and($frozen['max_qty'])->toEqual(8)
        ->and($frozen['amount_minor'])->toBe(600)
        ->and($frozen['setup_minor'])->toBe(300)
        ->and($order->subtotal_minor)->toBe(1900);

    expect(fn () => Meteric::createOrder()->account($acc)->add($monthly)->chooseOption($value, 9))
        ->toThrow(InvalidArgumentException::class, 'above the maximum');
});
