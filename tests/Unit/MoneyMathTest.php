<?php

declare(strict_types=1);

use Brick\Money\Money;
use Meteric\Support\MoneyMath;

it('rounds a sub-cent rate times quantity to currency minor', function () {
    // 1250.5 GB at €0.001/GB = €1.2505 -> €1.25
    $amount = MoneyMath::fromRate('1250.5', '0.00100000', 'EUR');

    expect($amount->getMinorAmount()->toInt())->toBe(125)
        ->and((string) $amount->getAmount())->toBe('1.25');
});

it('handles micro rates', function () {
    // 1_000_000 requests at €0.0000125 = €12.50
    $amount = MoneyMath::fromRate('1000000', '0.00001250', 'EUR');

    expect($amount->getMinorAmount()->toInt())->toBe(1250);
});

it('respects currency scale for zero-decimal currencies', function () {
    // JPY has scale 0
    $amount = MoneyMath::fromRate('3', '500.4', 'JPY');

    expect($amount->getMinorAmount()->toInt())->toBe(1501); // 1501.2 -> 1501
});

it('reconciles a sum of rounded lines to the total', function () {
    $lines = [
        MoneyMath::fromRate('10.5', '0.001', 'EUR'),  // 0.0105 -> 0.01
        MoneyMath::fromRate('99.4', '0.001', 'EUR'),  // 0.0994 -> 0.10
        MoneyMath::fromRate('250', '0.001', 'EUR'),   // 0.25
    ];

    $total = array_reduce(
        $lines,
        fn (Money $carry, Money $l) => $carry->plus($l),
        Money::ofMinor(0, 'EUR'),
    );

    expect($total->getMinorAmount()->toInt())->toBe(1 + 10 + 25);
});
