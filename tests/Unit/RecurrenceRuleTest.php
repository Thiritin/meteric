<?php

declare(strict_types=1);

use Billify\Enums\Interval;
use Billify\Support\RecurrenceRule;
use Carbon\CarbonImmutable;

it('advances by dynamic intervals', function (Interval $interval, int $count, string $start, string $expected) {
    $rule = new RecurrenceRule($interval, $count);

    expect($rule->nextEnd(CarbonImmutable::parse($start))->toDateString())->toBe($expected);
})->with([
    'every 7 days' => [Interval::Day, 7, '2026-06-01', '2026-06-08'],
    'every 2 weeks' => [Interval::Week, 2, '2026-06-01', '2026-06-15'],
    'every 3 months' => [Interval::Month, 3, '2026-06-01', '2026-09-01'],
    'every 1 year' => [Interval::Year, 1, '2026-06-01', '2027-06-01'],
]);

it('clamps month overflow (Jan 31 + 1 month)', function () {
    $rule = new RecurrenceRule(Interval::Month, 1);

    expect($rule->nextEnd(CarbonImmutable::parse('2026-01-31'))->toDateString())->toBe('2026-02-28');
});

it('treats null count as one-off', function () {
    $rule = RecurrenceRule::oneOff();

    expect($rule->isRecurring())->toBeFalse();
});
