<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Meteric\Enums\LineKind;
use Meteric\Enums\OptionType;
use Meteric\Facades\Meteric;
use Meteric\Models\BillingAccount;
use Meteric\Models\Charge;
use Meteric\Models\Price;
use Meteric\Models\Product;
use Meteric\Models\ProductOption;
use Meteric\Models\ProductOptionValue;
use Meteric\Models\Subscription;

uses(RefreshDatabase::class);

function optAccount(): BillingAccount
{
    return BillingAccount::create(['owner_type' => 'user', 'owner_id' => '1', 'currency' => 'EUR']);
}

function optBasePrice(): Price
{
    $p = Product::create(['type' => 'vps', 'slug' => 'o-'.uniqid(), 'name' => 'VPS', 'pricing_model' => 'fixed']);

    return Price::create([
        'product_id' => $p->id, 'currency' => 'EUR', 'amount_minor' => 1000,
        'pricing_model' => 'fixed', 'interval' => 'month', 'interval_count' => 1,
    ]);
}

/** A volume-tiered option price: 1-10 at 2.00, 11+ at 1.50 each. */
function optVolumePrice(string $product): Price
{
    return Price::create([
        'product_id' => $product, 'currency' => 'EUR', 'purpose' => 'option',
        'pricing_model' => 'volume', 'interval' => 'month', 'interval_count' => 1,
        'tiers' => [['up_to' => 10, 'unit_minor' => 200], ['up_to' => null, 'unit_minor' => 150]],
    ]);
}

function optSub(BillingAccount $acc, Price $base): Subscription
{
    return Meteric::subscribe()->account($acc)->at(CarbonImmutable::parse('2026-06-01Z'))->add($base, 1)->create();
}

it('re-bills a configurable option every renewal, tier-priced', function () {
    $acc = optAccount();
    $base = optBasePrice();
    $sub = optSub($acc, $base);
    $item = $sub->items()->first();
    $opt = optVolumePrice($base->product_id);

    // 4 slots: volume tier 1 to 10 at 2.00 = 8.00.
    Meteric::setOption($item, 'slots', '4', OptionType::Quantity->value, $opt, 4, CarbonImmutable::parse('2026-06-01Z'));

    Meteric::renew($sub->fresh(), CarbonImmutable::parse('2026-07-01Z'));

    $julyOption = Charge::where('kind', LineKind::Option->value)
        ->whereRaw("lower(covers) = '2026-07-01 00:00:00+00'")->first();

    expect($julyOption)->not->toBeNull()
        ->and($julyOption->amount_minor)->toBe(800)
        ->and($julyOption->quantity)->toBe(4.0);
});

it('does not double-bill an option when renew runs twice for the same period', function () {
    $acc = optAccount();
    $base = optBasePrice();
    $sub = optSub($acc, $base);
    $item = $sub->items()->first();
    Meteric::setOption($item, 'slots', '4', OptionType::Quantity->value, optVolumePrice($base->product_id), 4, CarbonImmutable::parse('2026-06-01Z'));

    Meteric::renew($sub->fresh(), CarbonImmutable::parse('2026-07-01Z'));
    Meteric::renew($sub->fresh(), CarbonImmutable::parse('2026-07-01Z'));

    $julyOptions = Charge::where('kind', LineKind::Option->value)
        ->whereRaw("lower(covers) = '2026-07-01 00:00:00+00'")->count();

    expect($julyOptions)->toBe(1);
});

it('enforces min and max on a quantity option', function () {
    $acc = optAccount();
    $sub = optSub($acc, optBasePrice());
    $item = $sub->items()->first();

    Meteric::setOption($item, 'slots', '20', OptionType::Quantity->value, null, 20, null, 1, 10);
})->throws(InvalidArgumentException::class);

it('charges a one-time setup fee once, when the option is first added', function () {
    $acc = optAccount();
    $base = optBasePrice();
    $sub = optSub($acc, $base);
    $item = $sub->items()->first();
    $opt = Price::create([
        'product_id' => $base->product_id, 'currency' => 'EUR', 'purpose' => 'option',
        'pricing_model' => 'fixed', 'amount_minor' => 200, 'setup_fee_minor' => 500,
        'interval' => 'month', 'interval_count' => 1,
    ]);

    Meteric::setOption($item, 'ddos', 'on', OptionType::Toggle->value, $opt, 1, CarbonImmutable::parse('2026-06-15Z'));
    Meteric::setOption($item, 'ddos', 'on', OptionType::Toggle->value, $opt, 1, CarbonImmutable::parse('2026-06-20Z'));

    $setups = Charge::where('kind', LineKind::Setup->value)->get();

    expect($setups)->toHaveCount(1)
        ->and($setups->first()->amount_minor)->toBe(500)
        ->and($setups->first()->covers)->toBeNull();
});

it('selects a catalog option value through chooseOption with volume pricing', function () {
    $acc = optAccount();
    $base = optBasePrice();
    $sub = optSub($acc, $base);
    $item = $sub->items()->first();

    $option = ProductOption::create([
        'product_id' => $base->product_id, 'key' => 'ips', 'label' => 'Extra IPs',
        'type' => OptionType::Quantity->value, 'min_qty' => 1, 'max_qty' => 256,
    ]);
    $value = ProductOptionValue::create([
        'option_id' => $option->id, 'value' => 'ipv4', 'label' => 'IPv4',
        'price_id' => optVolumePrice($base->product_id)->id,
    ]);

    // 60 IPs: volume lands in 11+ tier at 1.50 = 90.00.
    Meteric::chooseOption($item, $value, 60, CarbonImmutable::parse('2026-06-01Z'));
    Meteric::renew($sub->fresh(), CarbonImmutable::parse('2026-07-01Z'));

    $julyOption = Charge::where('kind', LineKind::Option->value)
        ->whereRaw("lower(covers) = '2026-07-01 00:00:00+00'")->first();

    expect($julyOption->amount_minor)->toBe(9000)
        ->and($item->options()->where('key', 'ips')->first()->max_qty)->toBe(256.0);
});

it('re-bills an addon every renewal', function () {
    $acc = optAccount();
    $base = optBasePrice();
    $sub = optSub($acc, $base);
    $item = $sub->items()->first();
    $addonPrice = Price::create([
        'product_id' => $base->product_id, 'currency' => 'EUR', 'purpose' => 'addon',
        'pricing_model' => 'fixed', 'amount_minor' => 300, 'interval' => 'month', 'interval_count' => 1,
    ]);

    Meteric::addAddon($item, $addonPrice, null, 1, CarbonImmutable::parse('2026-06-01Z'));
    Meteric::renew($sub->fresh(), CarbonImmutable::parse('2026-07-01Z'));

    $julyAddon = Charge::where('kind', LineKind::Addon->value)
        ->whereRaw("lower(covers) = '2026-07-01 00:00:00+00'")->first();

    expect($julyAddon)->not->toBeNull()
        ->and($julyAddon->amount_minor)->toBe(300);
});
