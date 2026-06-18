<?php

declare(strict_types=1);

use Billify\Proration\Prorator;
use Billify\Support\Period;
use Brick\Money\Money;
use Carbon\CarbonImmutable;

function junePeriod(): Period
{
    return new Period(
        CarbonImmutable::parse('2026-06-01T00:00:00Z'),
        CarbonImmutable::parse('2026-07-01T00:00:00Z'),
    );
}

it('prorates a mid-period charge by seconds', function () {
    $prorator = new Prorator('second');
    // 5 of 30 days remaining on a €30.00 plan -> €5.00
    $proration = $prorator->for(junePeriod(), CarbonImmutable::parse('2026-06-26T00:00:00Z'), Money::of('30.00', 'EUR'));

    expect($proration->amount()->getMinorAmount()->toInt())->toBe(500);
});

it('computes an upgrade swap as credit-old plus charge-new', function () {
    $prorator = new Prorator('second');
    // Upgrade at day 26 (5/30 remaining): credit -€5 of old €30, charge +€10 of new €60.
    $delta = $prorator->swap(
        junePeriod(),
        CarbonImmutable::parse('2026-06-26T00:00:00Z'),
        oldFull: Money::of('30.00', 'EUR'),
        newFull: Money::of('60.00', 'EUR'),
    );

    expect($delta->getMinorAmount()->toInt())->toBe(500); // +10 - 5
});

it('returns zero for a change at period end', function () {
    $prorator = new Prorator('second');
    $proration = $prorator->for(junePeriod(), CarbonImmutable::parse('2026-07-01T00:00:00Z'), Money::of('30.00', 'EUR'));

    expect($proration->amount()->getMinorAmount()->toInt())->toBe(0);
});

it('returns full amount for a change at period start', function () {
    $prorator = new Prorator('second');
    $proration = $prorator->for(junePeriod(), CarbonImmutable::parse('2026-06-01T00:00:00Z'), Money::of('30.00', 'EUR'));

    expect($proration->amount()->getMinorAmount()->toInt())->toBe(3000);
});
