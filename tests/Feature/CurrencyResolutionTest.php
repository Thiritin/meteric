<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Meteric\Facades\Meteric;
use Meteric\Models\Price;
use Meteric\Models\Product;

uses(RefreshDatabase::class);

function currencyProduct(): Product
{
    return Product::create(['type' => 'vps', 'slug' => 'cur-'.uniqid(), 'name' => 'VPS', 'pricing_model' => 'fixed']);
}

function currencyPrice(Product $product, string $currency, int $minor): Price
{
    return Price::create([
        'product_id' => $product->id, 'currency' => $currency, 'amount_minor' => $minor,
        'pricing_model' => 'fixed', 'interval' => 'month', 'interval_count' => 1,
    ]);
}

it('lists the default currency first and deduplicates the configured list', function () {
    config(['meteric.currency' => 'EUR', 'meteric.currencies' => ['chf', 'EUR', 'CHF']]);

    expect(Meteric::currencies())->toBe(['EUR', 'CHF']);
});

it('resolves a country to its mapped currency and everything else to the default', function () {
    config([
        'meteric.currency' => 'EUR',
        'meteric.currencies' => ['EUR', 'CHF'],
        'meteric.country_currencies' => ['CH' => 'CHF', 'LI' => 'chf', 'NO' => 'NOK'],
    ]);

    expect(Meteric::currencyForCountry('CH'))->toBe('CHF')
        ->and(Meteric::currencyForCountry('ch'))->toBe('CHF')
        ->and(Meteric::currencyForCountry('LI'))->toBe('CHF')
        // Mapped to a currency the installation does not trade in: the default,
        // so withdrawing a currency does not need the map cleaned up first.
        ->and(Meteric::currencyForCountry('NO'))->toBe('EUR')
        ->and(Meteric::currencyForCountry('PT'))->toBe('EUR')
        ->and(Meteric::currencyForCountry(null))->toBe('EUR')
        ->and(Meteric::currencyForCountry(''))->toBe('EUR');
});

it('needs no map when one currency is traded in', function () {
    config(['meteric.currency' => 'EUR', 'meteric.currencies' => ['EUR'], 'meteric.country_currencies' => []]);

    expect(Meteric::currencies())->toBe(['EUR'])
        ->and(Meteric::currencyForCountry('CH'))->toBe('EUR');
});

it('sells a product in the buyer currency where it has one and the default where it does not', function () {
    config(['meteric.currency' => 'EUR', 'meteric.currencies' => ['EUR', 'CHF']]);

    $both = currencyProduct();
    currencyPrice($both, 'EUR', 1000);
    currencyPrice($both, 'CHF', 1900);

    $eurOnly = currencyProduct();
    currencyPrice($eurOnly, 'EUR', 1000);

    $unpriced = currencyProduct();

    expect($both->currencyFor('CHF'))->toBe('CHF')
        ->and($both->currencyFor('EUR'))->toBe('EUR')
        ->and($eurOnly->currencyFor('CHF'))->toBe('EUR')
        ->and($eurOnly->currencyFor('chf'))->toBe('EUR')
        ->and($unpriced->currencyFor('CHF'))->toBeNull()
        ->and($unpriced->currencyFor('EUR'))->toBeNull();
});

it('falls back to a hand typed row rather than converting an amount', function () {
    config(['meteric.currency' => 'EUR', 'meteric.currencies' => ['EUR', 'CHF']]);

    $product = currencyProduct();
    currencyPrice($product, 'EUR', 1000);
    currencyPrice($product, 'CHF', 700);

    // The CHF price is below the EUR one, which no exchange rate would produce.
    // That is the feature: the rate is set by hand, per market.
    $currency = $product->currencyFor('CHF');

    expect($currency)->toBe('CHF')
        ->and($product->priceFor($currency)->amount_minor)->toBe(700)
        ->and($product->priceFor($currency)->currency)->toBe('CHF');
});
