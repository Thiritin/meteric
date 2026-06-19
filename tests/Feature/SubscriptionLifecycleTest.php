<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Meteric\Enums\DowngradePolicy;
use Meteric\Enums\SubscriptionState;
use Meteric\Facades\Meteric;
use Meteric\Models\BillingAccount;
use Meteric\Models\Charge;
use Meteric\Models\Price;
use Meteric\Models\Product;
use Meteric\Models\Subscription;

uses(RefreshDatabase::class);

function planPrice(int $minor, string $slug): Price
{
    $product = Product::create(['type' => 'vps', 'slug' => $slug.'-'.uniqid(), 'name' => $slug, 'pricing_model' => 'fixed']);

    return Price::create([
        'product_id' => $product->id, 'currency' => 'EUR', 'amount_minor' => $minor,
        'pricing_model' => 'fixed', 'interval' => 'month', 'interval_count' => 1,
    ]);
}

function freshAccount(): BillingAccount
{
    return BillingAccount::create(['owner_type' => 'user', 'owner_id' => '1', 'currency' => 'EUR']);
}

function subAt(BillingAccount $acc, Price $price, string $at): Subscription
{
    return Meteric::subscribe()->account($acc)->at(CarbonImmutable::parse($at))->add($price, 1)->create();
}

it('renews the next cycle when the period has elapsed', function () {
    $acc = freshAccount();
    $sub = subAt($acc, planPrice(1000, 'std'), '2026-06-01T00:00:00Z');

    // First cycle: one charge for June.
    expect(Charge::where('subscription_id', $sub->id)->count())->toBe(1);

    // Renew after July starts → second charge for July.
    $created = Meteric::renew($sub, CarbonImmutable::parse('2026-07-02T00:00:00Z'));

    expect($created)->toHaveCount(1)
        ->and(Charge::where('subscription_id', $sub->id)->count())->toBe(2);
});

it('does not renew before the period elapses', function () {
    $acc = freshAccount();
    $sub = subAt($acc, planPrice(1000, 'std'), '2026-06-01T00:00:00Z');

    $created = Meteric::renew($sub, CarbonImmutable::parse('2026-06-10T00:00:00Z'));

    expect($created)->toHaveCount(0)
        ->and(Charge::where('subscription_id', $sub->id)->count())->toBe(1);
});

it('prorates an immediate plan change (credit old + charge new)', function () {
    $acc = freshAccount();
    $small = planPrice(1000, 'small');
    $large = planPrice(3000, 'large');
    $sub = subAt($acc, $small, '2026-06-01T00:00:00Z');
    $item = $sub->items->first();
    $item->setRelation('subscription', $sub);
    $item->setRelation('price', $small);

    // Upgrade halfway through June (15 days left of 30) — charges the difference now.
    Meteric::changePlan($item, $large, at: CarbonImmutable::parse('2026-06-16T00:00:00Z'));

    $charges = Charge::where('subscription_id', $sub->id)->get();
    // base 1000 + credit (~-500 unused small) + prorated large (~+1500)
    expect($charges)->toHaveCount(3)
        ->and($item->fresh()->price_id)->toBe($large->id);

    $net = $charges->sum('amount_minor');
    expect($net)->toBe(1000 - 500 + 1500); // 2000
});

it('defers a downgrade to the next renewal (contracts)', function () {
    $acc = freshAccount();
    $large = planPrice(3000, 'large');
    $small = planPrice(1000, 'small');
    $sub = subAt($acc, $large, '2026-06-01T00:00:00Z');
    $item = $sub->items->first();
    $item->setRelation('subscription', $sub);
    $item->setRelation('price', $large);

    Meteric::changePlan($item, $small, DowngradePolicy::Defer, CarbonImmutable::parse('2026-06-16T00:00:00Z'));

    // No money moved; still on the large plan until the period ends.
    expect(Charge::where('subscription_id', $sub->id)->count())->toBe(1) // only the original
        ->and($item->fresh()->price_id)->toBe($large->id)
        ->and($item->fresh()->pending_change['price_id'])->toBe($small->id);

    // Renewal applies the change and bills the lower price.
    Meteric::renew($sub, CarbonImmutable::parse('2026-07-02T00:00:00Z'));
    expect($item->fresh()->price_id)->toBe($small->id);
    expect(Charge::where('subscription_id', $sub->id)->where('amount_minor', 1000)->exists())->toBeTrue();
});

it('discards a downgrade immediately with no refund (prepaid)', function () {
    $acc = freshAccount();
    $large = planPrice(3000, 'large');
    $small = planPrice(1000, 'small');
    $sub = subAt($acc, $large, '2026-06-01T00:00:00Z');
    $item = $sub->items->first();
    $item->setRelation('subscription', $sub);
    $item->setRelation('price', $large);

    Meteric::changePlan($item, $small, DowngradePolicy::Discard, CarbonImmutable::parse('2026-06-16T00:00:00Z'));

    // Switched now, no credit/refund — only the original €30 charge exists.
    expect($item->fresh()->price_id)->toBe($small->id)
        ->and(Charge::where('subscription_id', $sub->id)->count())->toBe(1);
});

it('cancels immediately', function () {
    $acc = freshAccount();
    $sub = subAt($acc, planPrice(1000, 'std'), '2026-06-01T00:00:00Z');

    Meteric::cancel($sub, 'now', CarbonImmutable::parse('2026-06-10T00:00:00Z'));

    expect($sub->fresh()->state)->toBe(SubscriptionState::Canceled);
});

it('schedules a period-end cancel', function () {
    $acc = freshAccount();
    $sub = subAt($acc, planPrice(1000, 'std'), '2026-06-01T00:00:00Z');

    Meteric::cancel($sub, 'period_end');

    expect($sub->fresh()->state)->toBe(SubscriptionState::Active)
        ->and($sub->fresh()->cancel_at)->not->toBeNull();
});
