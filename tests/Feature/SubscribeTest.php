<?php

declare(strict_types=1);

use Billify\Anchoring\PeriodPlanner;
use Billify\Charges\ChargeAccruer;
use Billify\Enums\AnchorMode;
use Billify\Enums\ChargeState;
use Billify\Enums\FirstPeriodPolicy;
use Billify\Enums\SubscriptionState;
use Billify\Facades\Billify;
use Billify\Models\BillingAccount;
use Billify\Models\BillingPeriod;
use Billify\Models\Charge;
use Billify\Models\Price;
use Billify\Models\Product;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function vpsPrice(int $minor = 1000): Price
{
    $product = Product::create(['type' => 'vps', 'slug' => 'vps-'.uniqid(), 'name' => 'VPS', 'pricing_model' => 'fixed']);

    return Price::create([
        'product_id' => $product->id, 'currency' => 'EUR', 'amount_minor' => $minor,
        'pricing_model' => 'fixed', 'interval' => 'month', 'interval_count' => 1,
    ]);
}

function account(): BillingAccount
{
    return BillingAccount::create(['owner_type' => 'user', 'owner_id' => '1', 'currency' => 'EUR']);
}

it('creates a subscription and accrues the first cycle (prorate_plus_full)', function () {
    $sub = Billify::subscribe()
        ->account(account())
        ->anchor(AnchorMode::FixedDay, 1)
        ->firstPeriod(FirstPeriodPolicy::ProratePlusFull)
        ->at(CarbonImmutable::parse('2026-06-25T00:00:00Z'))
        ->add(vpsPrice(1000), 1)
        ->create();

    expect($sub->state)->toBe(SubscriptionState::Active)
        ->and($sub->items)->toHaveCount(1);

    // stub (6/30 * 1000 = 200) + full month (1000) = two pending charges
    $charges = Charge::where('subscription_id', $sub->id)->orderBy('amount_minor')->get();
    expect($charges)->toHaveCount(2)
        ->and($charges[0]->amount_minor)->toBe(200)
        ->and($charges[1]->amount_minor)->toBe(1000)
        ->and($charges->pluck('state')->unique()->all())->toBe([ChargeState::Pending]);

    // both windows reserved in the guard table
    expect(BillingPeriod::where('item_id', $sub->items->first()->id)->count())->toBe(2);
});

it('bills the accrued charges into an invoice', function () {
    $acc = account();
    Billify::subscribe()->account($acc)
        ->at(CarbonImmutable::parse('2026-06-01T00:00:00Z'))
        ->add(vpsPrice(1000), 1)
        ->create();

    $invoice = Billify::invoicePending($acc);

    expect($invoice)->not->toBeNull()
        ->and($invoice->subtotal_minor)->toBe(1000);
});

it('defers billing during a trial', function () {
    $acc = account();
    $sub = Billify::subscribe()->account($acc)
        ->trialDays(14)
        ->at(CarbonImmutable::parse('2026-06-01T00:00:00Z'))
        ->add(vpsPrice(1000), 1)
        ->create();

    expect($sub->state)->toBe(SubscriptionState::Trialing)
        ->and(Charge::where('subscription_id', $sub->id)->count())->toBe(0);
});

it('is idempotent — re-accruing the same window bills nothing extra', function () {
    $acc = account();
    $price = vpsPrice(1000);
    $sub = Billify::subscribe()->account($acc)
        ->at(CarbonImmutable::parse('2026-06-01T00:00:00Z'))
        ->add($price, 1)
        ->create();

    $item = $sub->items->first();
    $item->setRelation('subscription', $sub);
    $item->setRelation('price', $price);

    $planner = app(PeriodPlanner::class);
    $plan = $planner->plan(CarbonImmutable::parse('2026-06-01T00:00:00Z'), $price->recurrence());
    app(ChargeAccruer::class)->accrue($item, $plan);

    expect(Charge::where('subscription_id', $sub->id)->count())->toBe(1);
});
