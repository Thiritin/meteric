<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Meteric\Support\Period;

it('computes total and remaining seconds', function () {
    $p = new Period(
        CarbonImmutable::parse('2026-06-01T00:00:00Z'),
        CarbonImmutable::parse('2026-07-01T00:00:00Z'),
    );

    expect($p->totalSeconds())->toBe(30 * 86400)
        ->and($p->remainingSecondsFrom(CarbonImmutable::parse('2026-06-26T00:00:00Z')))->toBe(5 * 86400);
});

it('clamps remaining at the edges', function () {
    $p = new Period(
        CarbonImmutable::parse('2026-06-01T00:00:00Z'),
        CarbonImmutable::parse('2026-07-01T00:00:00Z'),
    );

    expect($p->remainingSecondsFrom(CarbonImmutable::parse('2026-05-01T00:00:00Z')))->toBe(30 * 86400)
        ->and($p->remainingSecondsFrom(CarbonImmutable::parse('2026-08-01T00:00:00Z')))->toBe(0);
});

it('round-trips a tstzrange literal', function () {
    $p = Period::fromRange('["2026-06-01T00:00:00+00:00","2026-07-01T00:00:00+00:00")');

    expect($p)->not->toBeNull()
        ->and($p->totalSeconds())->toBe(30 * 86400);
});

it('rejects an inverted period', function () {
    new Period(
        CarbonImmutable::parse('2026-07-01T00:00:00Z'),
        CarbonImmutable::parse('2026-06-01T00:00:00Z'),
    );
})->throws(InvalidArgumentException::class);
