<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Meteric\Enums\DowngradePolicy;
use Meteric\Enums\LineKind;
use Meteric\Enums\SubscriptionState;
use Meteric\Enums\UpgradePolicy;
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

it('catches up every missed cycle and is idempotent at the same instant', function () {
    $acc = freshAccount();
    $sub = subAt($acc, planPrice(1000, 'std'), '2026-06-01T00:00:00Z');

    // First cycle (June) already billed.
    expect(Charge::where('subscription_id', $sub->id)->count())->toBe(1);

    // Renew well past two more cycle ends → bills July and August in one call.
    $at = CarbonImmutable::parse('2026-08-15T00:00:00Z');
    $created = Meteric::renew($sub, $at);

    expect($created)->toHaveCount(2)
        ->and(Charge::where('subscription_id', $sub->id)->count())->toBe(3);

    // Re-running at the same instant accrues nothing more (the guard holds).
    $again = Meteric::renew($sub->fresh(), $at);
    expect($again)->toHaveCount(0)
        ->and(Charge::where('subscription_id', $sub->id)->count())->toBe(3);
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

    Meteric::changePlan($item, $small, DowngradePolicy::Defer, at: CarbonImmutable::parse('2026-06-16T00:00:00Z'));

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

    Meteric::changePlan($item, $small, DowngradePolicy::Discard, at: CarbonImmutable::parse('2026-06-16T00:00:00Z'));

    // Switched now, no credit/refund — only the original €30 charge exists.
    expect($item->fresh()->price_id)->toBe($small->id)
        ->and(Charge::where('subscription_id', $sub->id)->count())->toBe(1);
});

it('never issues a negative invoice; credits wait for charges', function () {
    $acc = freshAccount();
    Charge::create([
        'account_id' => $acc->id, 'origin_type' => 'manual', 'origin_id' => (string) Str::uuid(),
        'kind' => LineKind::Credit->value, 'billing_mode' => 'in_advance', 'state' => 'pending',
        'title' => 'VPS', 'description' => 'Unused VPS', 'quantity' => 1, 'amount_minor' => -500,
        'currency' => 'EUR', 'idempotency_key' => (string) Str::uuid(),
    ]);

    // A lone credit must not produce a negative invoice.
    expect(Meteric::invoicePending($acc))->toBeNull();

    // A charge that outweighs it lets the credit land as a negative line.
    Charge::create([
        'account_id' => $acc->id, 'origin_type' => 'manual', 'origin_id' => (string) Str::uuid(),
        'kind' => LineKind::Recurring->value, 'billing_mode' => 'in_advance', 'state' => 'pending',
        'title' => 'VPS', 'description' => 'VPS', 'quantity' => 1, 'amount_minor' => 2000,
        'currency' => 'EUR', 'idempotency_key' => (string) Str::uuid(),
    ]);

    $invoice = Meteric::invoicePending($acc);
    expect($invoice)->not->toBeNull()
        ->and($invoice->subtotal_minor)->toBe(1500)   // 2000 charge minus 500 credit
        ->and($invoice->total_minor)->toBeGreaterThan(0)
        ->and($invoice->lines()->count())->toBe(2);  // both lines itemized, not summarized
});

it('defers an upgrade to the next renewal', function () {
    $acc = freshAccount();
    $small = planPrice(1000, 'small');
    $large = planPrice(3000, 'large');
    $sub = subAt($acc, $small, '2026-06-01T00:00:00Z');
    $item = $sub->items->first();
    $item->setRelation('subscription', $sub);
    $item->setRelation('price', $small);

    Meteric::changePlan($item, $large, upgrade: UpgradePolicy::Defer, at: CarbonImmutable::parse('2026-06-16T00:00:00Z'));

    // No mid-cycle money; the swap is queued for renewal.
    expect(Charge::where('subscription_id', $sub->id)->count())->toBe(1)
        ->and($item->fresh()->price_id)->toBe($small->id)
        ->and($item->fresh()->pending_change['price_id'])->toBe($large->id);

    Meteric::renew($sub, CarbonImmutable::parse('2026-07-02T00:00:00Z'));
    expect($item->fresh()->price_id)->toBe($large->id);
});

it('charges the full new plan on a full_now upgrade', function () {
    $acc = freshAccount();
    $small = planPrice(1000, 'small');
    $large = planPrice(3000, 'large');
    $sub = subAt($acc, $small, '2026-06-01T00:00:00Z');
    $item = $sub->items->first();
    $item->setRelation('subscription', $sub);
    $item->setRelation('price', $small);

    Meteric::changePlan($item, $large, upgrade: UpgradePolicy::FullNow, at: CarbonImmutable::parse('2026-06-16T00:00:00Z'));

    // Swapped now, full new plan charged (no proration, no old credit): 1000 + 3000.
    $charges = Charge::where('subscription_id', $sub->id)->get();
    expect($charges)->toHaveCount(2)
        ->and($item->fresh()->price_id)->toBe($large->id)
        ->and((int) $charges->sum('amount_minor'))->toBe(4000)
        ->and($charges->where('amount_minor', 3000)->count())->toBe(1)
        // The cycle restarts at the change date: a fresh full month from 06-16.
        ->and($item->fresh()->current_period->start->toDateString())->toBe('2026-06-16')
        ->and($item->fresh()->current_period->end->toDateString())->toBe('2026-07-16');

    // The next renewal is a full interval out: the old 07-01 boundary bills nothing.
    expect(Meteric::renew($sub->fresh(), CarbonImmutable::parse('2026-07-02T00:00:00Z')))->toHaveCount(0);
    expect(Meteric::renew($sub->fresh(), CarbonImmutable::parse('2026-07-17T00:00:00Z')))->toHaveCount(1);
});

it('credits the unused value on a credit downgrade', function () {
    $acc = freshAccount();
    $large = planPrice(3000, 'large');
    $small = planPrice(1000, 'small');
    $sub = subAt($acc, $large, '2026-06-01T00:00:00Z');
    $item = $sub->items->first();
    $item->setRelation('subscription', $sub);
    $item->setRelation('price', $large);

    // Halfway through June: credit the unused half of the large plan (~-1500).
    Meteric::changePlan($item, $small, DowngradePolicy::Credit, at: CarbonImmutable::parse('2026-06-16T00:00:00Z'));

    $charges = Charge::where('subscription_id', $sub->id)->get();
    expect($item->fresh()->price_id)->toBe($small->id)
        ->and($charges)->toHaveCount(2)                       // base 3000 + credit
        ->and($charges->where('amount_minor', -1500)->count())->toBe(1)
        ->and((int) $charges->sum('amount_minor'))->toBe(1500);
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
