<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Meteric\Enums\LineKind;
use Meteric\Enums\OptionType;
use Meteric\Exceptions\CatalogRowInactive;
use Meteric\Facades\Meteric;
use Meteric\Models\BillingAccount;
use Meteric\Models\Charge;
use Meteric\Models\Price;
use Meteric\Models\Product;
use Meteric\Models\ProductAddon;
use Meteric\Models\ProductOption;
use Meteric\Models\ProductOptionValue;

uses(RefreshDatabase::class);

function activeProduct(string $type = 'vps'): Product
{
    return Product::create(['type' => $type, 'slug' => $type.'-'.uniqid(), 'name' => ucfirst($type), 'pricing_model' => 'fixed']);
}

function activePrice(Product $product, int $minor, array $extra = []): Price
{
    return Price::create([
        'product_id' => $product->id, 'currency' => 'EUR', 'amount_minor' => $minor,
        'pricing_model' => 'fixed', 'interval' => 'month', 'interval_count' => 1,
        ...$extra,
    ]);
}

it('lists only active options with at least one active value', function () {
    $vps = activeProduct();
    $price = activePrice($vps, 200, ['purpose' => 'option']);

    $os = ProductOption::create(['product_id' => $vps->id, 'key' => 'os', 'type' => OptionType::Choice->value, 'sort' => 1]);
    ProductOptionValue::create(['option_id' => $os->id, 'value' => 'debian', 'price_id' => $price->id]);
    ProductOptionValue::create(['option_id' => $os->id, 'value' => 'centos', 'price_id' => $price->id, 'active' => false]);

    $retired = ProductOption::create(['product_id' => $vps->id, 'key' => 'panel', 'type' => OptionType::Toggle->value, 'active' => false, 'sort' => 2]);
    ProductOptionValue::create(['option_id' => $retired->id, 'value' => 'on']);

    $empty = ProductOption::create(['product_id' => $vps->id, 'key' => 'ddos', 'type' => OptionType::Toggle->value, 'sort' => 3]);
    ProductOptionValue::create(['option_id' => $empty->id, 'value' => 'on', 'active' => false]);

    $catalog = $vps->optionCatalog();

    expect($catalog)->toHaveCount(1)
        ->and($catalog[0]['key'])->toBe('os')
        ->and(array_column($catalog[0]['values'], 'value'))->toBe(['debian'])
        ->and($os->fresh()->toDisplay()['values'])->toHaveCount(1);
});

it('lists only active addons', function () {
    $vps = activeProduct();
    $term = activePrice($vps, 1000);
    $ip = activeProduct('ipv4');
    activePrice($ip, 200);
    $backup = activeProduct('backup');
    activePrice($backup, 400);

    ProductAddon::create(['product_id' => $vps->id, 'addon_product_id' => $ip->id]);
    ProductAddon::create(['product_id' => $vps->id, 'addon_product_id' => $backup->id, 'active' => false]);

    expect(array_column($vps->addonCatalog($term), 'slug'))->toBe([$ip->slug]);
});

it('refuses to book an inactive value, an inactive option or an inactive addon on an order', function () {
    $acc = BillingAccount::create(['owner_type' => 'user', 'owner_id' => '1', 'currency' => 'EUR']);
    $vps = activeProduct();
    $term = activePrice($vps, 1000);
    $price = activePrice($vps, 200, ['purpose' => 'option']);

    $os = ProductOption::create(['product_id' => $vps->id, 'key' => 'os', 'type' => OptionType::Choice->value]);
    $centos = ProductOptionValue::create(['option_id' => $os->id, 'value' => 'centos', 'price_id' => $price->id, 'active' => false]);
    $retired = ProductOption::create(['product_id' => $vps->id, 'key' => 'panel', 'type' => OptionType::Toggle->value, 'active' => false]);
    $on = ProductOptionValue::create(['option_id' => $retired->id, 'value' => 'on']);

    $backup = activeProduct('backup');
    activePrice($backup, 400);
    $addon = ProductAddon::create(['product_id' => $vps->id, 'addon_product_id' => $backup->id, 'active' => false]);

    expect(fn () => Meteric::createOrder()->account($acc)->add($term)->chooseOption($centos))
        ->toThrow(CatalogRowInactive::class, 'centos');
    expect(fn () => Meteric::createOrder()->account($acc)->add($term)->chooseOption($on))
        ->toThrow(CatalogRowInactive::class, 'panel');
    expect(fn () => Meteric::createOrder()->account($acc)->add($term)->bookAddon($addon))
        ->toThrow(CatalogRowInactive::class, 'no longer offered');
});

it('keeps renewing a live item that references a value withdrawn from sale', function () {
    $acc = BillingAccount::create(['owner_type' => 'user', 'owner_id' => '1', 'currency' => 'EUR']);
    $vps = activeProduct();
    $term = activePrice($vps, 1000);
    $price = activePrice($vps, 200, ['purpose' => 'option']);
    $os = ProductOption::create(['product_id' => $vps->id, 'key' => 'os', 'type' => OptionType::Choice->value]);
    $centos = ProductOptionValue::create(['option_id' => $os->id, 'value' => 'centos', 'price_id' => $price->id]);

    $sub = Meteric::subscribe()->account($acc)->at(CarbonImmutable::parse('2026-06-01Z'))->add($term)->create();
    $item = $sub->items()->first();
    Meteric::chooseOption($item, $centos, 1, CarbonImmutable::parse('2026-06-01Z'));

    $centos->forceFill(['active' => false])->save();

    expect(fn () => Meteric::chooseOption($item, $centos->fresh(), 1))->toThrow(CatalogRowInactive::class);

    Meteric::renew($sub->fresh(), CarbonImmutable::parse('2026-07-01Z'));

    $july = Charge::where('kind', LineKind::Option->value)
        ->whereRaw("lower(covers) = '2026-07-01 00:00:00+00'")->first();
    expect($july)->not->toBeNull()
        ->and($july->amount_minor)->toBe(200);
});
