<?php

declare(strict_types=1);

use Brick\Money\Money;
use Carbon\CarbonImmutable;
use Meteric\Models\Coupon;

it('computes a percentage discount', function () {
    $coupon = new Coupon(['type' => 'percent', 'value' => 50]);

    $discount = $coupon->discountFor(Money::of('100.00', 'EUR'));

    expect($discount->getMinorAmount()->toInt())->toBe(-5000); // -€50
});

it('computes a fixed discount', function () {
    $coupon = new Coupon(['type' => 'fixed', 'value' => 0, 'value_minor' => 500, 'currency' => 'EUR']);

    $discount = $coupon->discountFor(Money::of('100.00', 'EUR'));

    expect($discount->getMinorAmount()->toInt())->toBe(-500); // -€5
});

it('is invalid outside its window', function () {
    $coupon = new Coupon([
        'type' => 'percent', 'value' => 10,
        'valid_from' => CarbonImmutable::parse('2026-06-01Z'),
        'valid_to' => CarbonImmutable::parse('2026-07-01Z'),
    ]);

    expect($coupon->isValidAt(CarbonImmutable::parse('2026-06-15Z')))->toBeTrue()
        ->and($coupon->isValidAt(CarbonImmutable::parse('2026-05-15Z')))->toBeFalse()
        ->and($coupon->isValidAt(CarbonImmutable::parse('2026-08-15Z')))->toBeFalse();
});

it('is invalid once redemptions are exhausted', function () {
    $coupon = new Coupon(['type' => 'percent', 'value' => 10, 'max_redemptions' => 2, 'redeemed_count' => 2]);

    expect($coupon->isValidAt(CarbonImmutable::parse('2026-06-15Z')))->toBeFalse();
});
