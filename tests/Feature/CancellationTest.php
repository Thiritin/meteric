<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Meteric\Enums\ItemState;
use Meteric\Enums\SubscriptionState;
use Meteric\Events\SubscriptionCanceled;
use Meteric\Facades\Meteric;
use Meteric\Models\BillingAccount;
use Meteric\Models\Price;
use Meteric\Models\Product;
use Meteric\Models\Subscription;

uses(RefreshDatabase::class);

function cncAccount(): BillingAccount
{
    return BillingAccount::create(['owner_type' => 'user', 'owner_id' => '1', 'currency' => 'EUR']);
}

function cncPlan(int $minor = 1000, int $noticeDays = 0): Price
{
    $product = Product::create([
        'type' => 'vps', 'slug' => 'cnc-'.uniqid(), 'name' => 'VPS', 'pricing_model' => 'fixed',
        'config' => ['cancel_notice_days' => $noticeDays],
    ]);

    return Price::create([
        'product_id' => $product->id, 'currency' => 'EUR', 'amount_minor' => $minor,
        'pricing_model' => 'fixed', 'interval' => 'month', 'interval_count' => 1,
    ]);
}

function cncSub(BillingAccount $acc, Price $price): Subscription
{
    return Meteric::subscribe()->account($acc)->at(CarbonImmutable::parse('2026-06-01Z'))->add($price, 1)->create();
}

it('schedules a period-end cancel and enacts it at the boundary', function () {
    $sub = cncSub(cncAccount(), cncPlan());

    Meteric::cancel($sub, 'period_end');
    expect($sub->fresh()->cancel_at->toDateString())->toBe('2026-07-01')
        ->and($sub->fresh()->state)->toBe(SubscriptionState::Active);

    // Before the boundary: nothing enacted.
    expect(Meteric::processDueCancellations(CarbonImmutable::parse('2026-06-20Z')))->toBe(0)
        ->and($sub->fresh()->state)->toBe(SubscriptionState::Active);

    // At the boundary: canceled, event fired, items closed.
    Event::fake([SubscriptionCanceled::class]);
    expect(Meteric::processDueCancellations(CarbonImmutable::parse('2026-07-01Z')))->toBe(1)
        ->and($sub->fresh()->state)->toBe(SubscriptionState::Canceled)
        ->and($sub->items()->where('state', ItemState::Active->value)->count())->toBe(0);
    Event::assertDispatched(SubscriptionCanceled::class);
});

it('stops billing at the cancellation boundary', function () {
    $sub = cncSub(cncAccount(), cncPlan());
    Meteric::cancel($sub, 'period_end'); // cancel_at = 2026-07-01

    // Renew far past the boundary: the July period and beyond are not billed.
    expect(Meteric::renew($sub->fresh(), CarbonImmutable::parse('2026-10-01Z')))->toHaveCount(0);
});

it('cancels to a specific later boundary and bills up to it', function () {
    $sub = cncSub(cncAccount(), cncPlan());

    $options = Meteric::cancellationOptions($sub, 3);
    expect($options)->toHaveCount(3)
        ->and($options[0]->toDateString())->toBe('2026-07-01')
        ->and($options[2]->toDateString())->toBe('2026-09-01');

    Meteric::cancel($sub, $options[2]); // cancel at 2026-09-01

    // Renew past it: bills July and August, then stops at the Sep boundary.
    expect(Meteric::renew($sub->fresh(), CarbonImmutable::parse('2026-10-01Z')))->toHaveCount(2)
        ->and(Meteric::processDueCancellations(CarbonImmutable::parse('2026-09-01Z')))->toBe(1);
});

it('enforces a contract notice window', function () {
    $sub = cncSub(cncAccount(), cncPlan(1000, noticeDays: 14));

    // On the 20th, cancelling for the 2026-07-01 end is past the 14-day cutoff (06-17).
    expect(fn () => Meteric::cancel($sub, 'period_end', CarbonImmutable::parse('2026-06-20Z')))
        ->toThrow(InvalidArgumentException::class);

    // The options skip the too-soon boundary and offer the next valid ones.
    test()->travelTo(CarbonImmutable::parse('2026-06-20Z'));
    $options = Meteric::cancellationOptions($sub, 2);
    expect($options[0]->toDateString())->toBe('2026-08-01');  // 07-01 dropped (notice passed)
});
