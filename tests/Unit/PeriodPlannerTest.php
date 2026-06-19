<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Meteric\Anchoring\PeriodPlanner;
use Meteric\Enums\AnchorMode;
use Meteric\Enums\FirstPeriodPolicy;
use Meteric\Enums\Interval;
use Meteric\Enums\LineKind;
use Meteric\Support\RecurrenceRule;

function monthly(): RecurrenceRule
{
    return new RecurrenceRule(Interval::Month, 1);
}

function signup(): CarbonImmutable
{
    return CarbonImmutable::parse('2026-06-25T00:00:00Z');
}

it('bills one full period from signup in anniversary mode', function () {
    $plan = (new PeriodPlanner)->plan(signup(), monthly(), AnchorMode::Signup);

    expect($plan->charges)->toHaveCount(1)
        ->and($plan->charges[0]->kind)->toBe(LineKind::Recurring)
        ->and($plan->charges[0]->period->end->toDateString())->toBe('2026-07-25')
        ->and($plan->ongoing->end->toDateString())->toBe('2026-07-25');
});

it('prorate_only bills just the stub to the anchor', function () {
    $plan = (new PeriodPlanner)->plan(signup(), monthly(), AnchorMode::FixedDay, 1, FirstPeriodPolicy::ProrateOnly);

    expect($plan->charges)->toHaveCount(1)
        ->and($plan->charges[0]->prorated)->toBeTrue()
        ->and($plan->charges[0]->period->start->toDateString())->toBe('2026-06-25')
        ->and($plan->charges[0]->period->end->toDateString())->toBe('2026-07-01')
        ->and($plan->ongoing->end->toDateString())->toBe('2026-07-01'); // renewal at anchor
});

it('prorate_plus_full bills stub plus the first full month', function () {
    $plan = (new PeriodPlanner)->plan(signup(), monthly(), AnchorMode::FixedDay, 1, FirstPeriodPolicy::ProratePlusFull);

    expect($plan->charges)->toHaveCount(2)
        ->and($plan->charges[0]->kind)->toBe(LineKind::Prorated)
        ->and($plan->charges[1]->kind)->toBe(LineKind::FullPeriod)
        ->and($plan->charges[1]->period->start->toDateString())->toBe('2026-07-01')
        ->and($plan->charges[1]->period->end->toDateString())->toBe('2026-08-01')
        ->and($plan->ongoing->end->toDateString())->toBe('2026-08-01');
});

it('full_period ignores the stub and anchors from signup', function () {
    $plan = (new PeriodPlanner)->plan(signup(), monthly(), AnchorMode::FixedDay, 1, FirstPeriodPolicy::FullPeriod);

    expect($plan->charges)->toHaveCount(1)
        ->and($plan->charges[0]->kind)->toBe(LineKind::FullPeriod)
        ->and($plan->charges[0]->period->end->toDateString())->toBe('2026-07-25');
});

it('free_until_anchor makes the stub free', function () {
    $plan = (new PeriodPlanner)->plan(signup(), monthly(), AnchorMode::FixedDay, 1, FirstPeriodPolicy::FreeUntilAnchor);

    expect($plan->charges)->toHaveCount(1)
        ->and($plan->charges[0]->free)->toBeTrue()
        ->and($plan->charges[0]->period->end->toDateString())->toBe('2026-07-01');
});

it('treats an on-anchor signup as already aligned (no stub)', function () {
    $onAnchor = CarbonImmutable::parse('2026-06-01T00:00:00Z');
    $plan = (new PeriodPlanner)->plan($onAnchor, monthly(), AnchorMode::FixedDay, 1, FirstPeriodPolicy::ProrateOnly);

    expect($plan->charges)->toHaveCount(1)
        ->and($plan->charges[0]->prorated)->toBeFalse()
        ->and($plan->charges[0]->period->end->toDateString())->toBe('2026-07-01');
});
