<?php

declare(strict_types=1);

use Meteric\Models\Price;
use Meteric\Pricing\Tiers;

// 1-10 @ €5, 11-50 @ €4, 51+ @ €3
function discountTiers(): array
{
    return [
        ['up_to' => 10, 'unit_minor' => 500],
        ['up_to' => 50, 'unit_minor' => 400],
        ['up_to' => null, 'unit_minor' => 300],
    ];
}

it('prices the whole quantity at the reached tier (volume)', function (float $qty, int $expected) {
    expect(Tiers::volume(discountTiers(), $qty, 'EUR')->getMinorAmount()->toInt())->toBe($expected);
})->with([
    'first tier' => [5.0, 2500],    // 5 × €5
    'edge of first' => [10.0, 5000], // 10 × €5
    'second tier' => [30.0, 12000],  // 30 × €4
    'top tier' => [60.0, 18000],     // 60 × €3 (the more you buy, the cheaper)
]);

it('prices each slice at its own tier (graduated)', function (float $qty, int $expected) {
    expect(Tiers::graduated(discountTiers(), $qty, 'EUR')->getMinorAmount()->toInt())->toBe($expected);
})->with([
    'within first' => [5.0, 2500],       // 5 × 500
    'into second' => [30.0, 5000 + 20 * 400], // 10×500 + 20×400 = 13000
    'into third' => [60.0, 5000 + 40 * 400 + 10 * 300], // 5000+16000+3000 = 24000
]);

it('drives Price::amountFor for a volume price', function () {
    $price = new Price([
        'currency' => 'EUR', 'pricing_model' => 'volume', 'tiers' => discountTiers(),
    ]);

    expect($price->amountFor(60)->getMinorAmount()->toInt())->toBe(18000)  // all 60 at €3
        ->and($price->amountFor(5)->getMinorAmount()->toInt())->toBe(2500);
});

it('drives Price::amountFor for a graduated price', function () {
    $price = new Price([
        'currency' => 'EUR', 'pricing_model' => 'tiered', 'tiers' => discountTiers(),
    ]);

    expect($price->amountFor(60)->getMinorAmount()->toInt())->toBe(24000);
});
