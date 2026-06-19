<?php

declare(strict_types=1);

use Meteric\Anchoring\PeriodPlanner;
use Meteric\Enums\AnchorMode;
use Meteric\Enums\FirstPeriodPolicy;
use Meteric\Models\Price;
use Meteric\Proration\Prorator;
use Meteric\Quoting\QuoteBuilder;
use Meteric\Support\FrozenClock;
use Meteric\Tax\NullTaxResolver;

function recurringPrice(int $minor = 1000): Price
{
    return new Price([
        'amount_minor' => $minor, 'currency' => 'EUR', 'pricing_model' => 'fixed',
        'purpose' => 'recurring', 'interval' => 'month', 'interval_count' => 1,
    ]);
}

function builder(): QuoteBuilder
{
    return new QuoteBuilder(
        clock: FrozenClock::at('2026-06-25T00:00:00Z'),
        prorator: new Prorator('second'),
        tax: new NullTaxResolver,
        planner: new PeriodPlanner,
        currency: 'EUR',
    );
}

it('quotes prorate_plus_full as stub + full period due now', function () {
    $quote = builder()
        ->anchor(AnchorMode::FixedDay, 1)
        ->firstPeriod(FirstPeriodPolicy::ProratePlusFull)
        ->add(recurringPrice(1000), 1, 'VPS')
        ->build();

    // stub 2026-06-25 → 07-01 = 6 days of the 30-day June cycle = 1000 * 6/30 = 200
    // + full month 1000  ⇒ due now 1200
    expect($quote->lines)->toHaveCount(2)
        ->and($quote->dueNowSubtotal->getMinorAmount()->toInt())->toBe(1200)
        ->and($quote->recurringTotal->getMinorAmount()->toInt())->toBe(1000)
        ->and($quote->interval)->toBe('month')
        ->and($quote->nextChargeAt->toDateString())->toBe('2026-08-01');
});

it('quotes anniversary billing as a single full period', function () {
    $quote = builder()->add(recurringPrice(999), 1, 'Plan')->build();

    expect($quote->lines)->toHaveCount(1)
        ->and($quote->dueNowTotal->getMinorAmount()->toInt())->toBe(999);
});

it('flags usage prices as estimated with the rate', function () {
    $usage = new Price([
        'currency' => 'EUR', 'pricing_model' => 'metered', 'purpose' => 'recurring',
        'unit_rate' => '0.00100000', 'interval' => 'month', 'interval_count' => 1,
    ]);

    $quote = builder()->add($usage, 100, 'Traffic')->build();

    expect($quote->estimated)->toBeTrue()
        ->and($quote->lines[0]->estimated)->toBeTrue()
        ->and($quote->lines[0]->unitRate)->toBe('0.00100000')
        ->and($quote->lines[0]->amount->getMinorAmount()->toInt())->toBe(0);
});

it('serializes to stable JSON', function () {
    $quote = builder()->add(recurringPrice(1000), 1, 'VPS')->build();
    $data = $quote->toArray();

    expect($data)->toHaveKeys(['currency', 'due_now', 'recurring', 'lines', 'estimated'])
        ->and($data['due_now'])->toHaveKeys(['subtotal_minor', 'tax_minor', 'total_minor'])
        ->and($data['lines'][0])->toHaveKeys(['label', 'kind', 'amount_minor', 'covers']);
});
