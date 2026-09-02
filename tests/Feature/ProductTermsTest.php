<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Meteric\Enums\Interval;
use Meteric\Enums\PricePurpose;
use Meteric\Models\Price;
use Meteric\Models\Product;

uses(RefreshDatabase::class);

function termProduct(): Product
{
    return Product::create(['type' => 'vps', 'slug' => 'term-'.uniqid(), 'name' => 'VPS', 'pricing_model' => 'fixed']);
}

function termPrice(Product $product, string $interval, int $count, int $minor, array $extra = []): Price
{
    return Price::create([
        'product_id' => $product->id, 'currency' => 'EUR', 'amount_minor' => $minor,
        'pricing_model' => 'fixed', 'interval' => $interval, 'interval_count' => $count,
        ...$extra,
    ]);
}

it('picks a price by term when a product is sold on several', function () {
    $product = termProduct();
    $monthly = termPrice($product, 'month', 1, 1000, ['valid_from' => '2026-01-01']);
    $yearly = termPrice($product, 'year', 1, 10000, ['valid_from' => '2026-02-01']);
    $quarterly = termPrice($product, 'month', 3, 2700, ['valid_from' => '2026-03-01']);

    expect($product->priceFor('EUR', PricePurpose::Recurring, Interval::Month)->id)->toBe($monthly->id)
        ->and($product->priceFor('EUR', PricePurpose::Recurring, Interval::Month, 3)->id)->toBe($quarterly->id)
        ->and($product->priceFor('EUR', PricePurpose::Recurring, Interval::Year)->id)->toBe($yearly->id)
        ->and($product->priceFor('EUR', PricePurpose::Recurring, Interval::Week))->toBeNull()
        // Without a term the newest current price still wins, as before.
        ->and($product->priceFor('EUR')->id)->toBe($quarterly->id);
});

it('lists the terms shortest first, one current price per term', function () {
    $product = termProduct();
    termPrice($product, 'year', 1, 10000);
    $superseded = termPrice($product, 'month', 1, 900, ['valid_from' => '2025-01-01', 'valid_to' => '2026-01-01']);
    $monthly = termPrice($product, 'month', 1, 1000, ['valid_from' => '2026-01-01']);
    termPrice($product, 'month', 6, 5400);
    Price::create(['product_id' => $product->id, 'currency' => 'EUR', 'amount_minor' => 2500, 'pricing_model' => 'one_off', 'purpose' => 'setup']);
    Price::create(['product_id' => $product->id, 'currency' => 'USD', 'amount_minor' => 1100, 'pricing_model' => 'fixed', 'interval' => 'month', 'interval_count' => 1]);

    $terms = $product->terms('EUR');

    expect($terms->map(fn (Price $p) => $p->interval_count.' '.$p->interval->value)->all())
        ->toBe(['1 month', '6 month', '1 year'])
        ->and($terms->first()->id)->toBe($monthly->id)
        ->and($terms->pluck('id'))->not->toContain($superseded->id);
});

it('renders the term catalog with a price per term', function () {
    $product = termProduct();
    termPrice($product, 'month', 1, 1000, ['setup_fee_minor' => 500]);
    termPrice($product, 'year', 1, 10000);

    $catalog = $product->termCatalog('EUR', qty: 2);

    expect($catalog)->toHaveCount(2)
        ->and($catalog[0]['interval'])->toBe('month')
        ->and($catalog[0]['interval_count'])->toBe(1)
        ->and($catalog[0]['amount_minor'])->toBe(2000)
        ->and($catalog[0]['amount'])->toBe('20.00')
        ->and($catalog[0]['setup_fee_minor'])->toBe(500)
        ->and($catalog[0]['currency'])->toBe('EUR')
        ->and($catalog[1]['interval'])->toBe('year')
        ->and($catalog[1]['amount_minor'])->toBe(20000);
});

it('sorts terms by calendar length, not by interval name', function () {
    $product = termProduct();
    termPrice($product, 'week', 2, 500);
    termPrice($product, 'day', 30, 1000);
    termPrice($product, 'month', 1, 1000);

    test()->travelTo(CarbonImmutable::parse('2026-06-01T00:00:00Z'));

    expect($product->terms('EUR')->map(fn (Price $p) => $p->interval_count.' '.$p->interval->value)->all())
        ->toBe(['2 week', '30 day', '1 month']);
});
