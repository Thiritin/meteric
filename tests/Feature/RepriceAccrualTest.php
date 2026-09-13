<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Meteric\Enums\AnchorMode;
use Meteric\Enums\ChargeState;
use Meteric\Enums\LineKind;
use Meteric\Exceptions\AccrualNotRepriceable;
use Meteric\Facades\Meteric;
use Meteric\Models\BillingAccount;
use Meteric\Models\Charge;
use Meteric\Models\Price;
use Meteric\Models\Product;
use Meteric\Models\SubscriptionItem;
use Meteric\Pricing\DiscountSpec;

uses(RefreshDatabase::class);

function raAccount(): BillingAccount
{
    return BillingAccount::create(['owner_type' => 'user', 'owner_id' => '1', 'currency' => 'EUR']);
}

function raPlan(int $minor): Price
{
    $product = Product::create(['type' => 'vps', 'slug' => 'ra-'.uniqid(), 'name' => 'VPS '.$minor, 'pricing_model' => 'fixed']);

    return Price::create([
        'product_id' => $product->id, 'currency' => 'EUR', 'amount_minor' => $minor,
        'pricing_model' => 'fixed', 'interval' => 'month', 'interval_count' => 1, 'billing_mode' => 'in_advance',
    ]);
}

function raItem(BillingAccount $account, Price $base): SubscriptionItem
{
    $subscription = Meteric::subscribe()->account($account)->at(CarbonImmutable::parse('2026-06-01Z'))
        ->add($base, 1, null, label: 'web1.example')->create();

    return $subscription->items->first()->setRelation('subscription', $subscription);
}

function raBase(SubscriptionItem $item): Charge
{
    return Charge::where('line_group', $item->id)
        ->whereIn('kind', [LineKind::Recurring->value, LineKind::Prorated->value, LineKind::FullPeriod->value])
        ->orderByDesc('created_at')
        ->first();
}

it('restates the accrued period at the amount agreed after it accrued', function () {
    $item = raItem(raAccount(), raPlan(1000));

    expect((int) raBase($item)->amount_minor)->toBe(1000);

    Meteric::overridePrice($item, 600);
    $changed = Meteric::repriceAccrual($item->fresh()->setRelation('subscription', $item->subscription));

    expect($changed)->toHaveCount(1)
        ->and((int) raBase($item)->amount_minor)->toBe(600)
        ->and((int) raBase($item)->unit_minor)->toBe(600);
});

it('reports nothing changed when the amount is already what the item bills', function () {
    $item = raItem(raAccount(), raPlan(1000));

    expect(Meteric::repriceAccrual($item))->toBe([]);
});

it('refuses a period whose charges have been invoiced', function () {
    $account = raAccount();
    $item = raItem($account, raPlan(1000));

    Meteric::invoicePending($account);

    expect(raBase($item)->state)->toBe(ChargeState::Invoiced);

    Meteric::overridePrice($item, 600);

    Meteric::repriceAccrual($item->fresh()->setRelation('subscription', $item->subscription));
})->throws(AccrualNotRepriceable::class);

it('re-prorates a part period over the window it was accrued for', function () {
    $account = raAccount();
    $plan = raPlan(1000);

    // Mid-month start: the first period is the half of June that is left.
    $subscription = Meteric::subscribe()->account($account)->at(CarbonImmutable::parse('2026-06-16Z'))
        ->add($plan, 1, null, label: 'web1.example')->anchor(AnchorMode::FixedDay, 1)->create();
    $item = $subscription->items->first()->setRelation('subscription', $subscription);

    $prorated = Charge::where('line_group', $item->id)->where('kind', LineKind::Prorated->value)->first();

    expect($prorated)->not->toBeNull();

    $wasMinor = (int) $prorated->amount_minor;

    Meteric::overridePrice($item, 500);
    Meteric::repriceAccrual($item->fresh()->setRelation('subscription', $subscription));

    // Half the price over the same fraction of the month: half the figure.
    expect((int) $prorated->fresh()->amount_minor)->toBe(intdiv($wasMinor, 2));
});

it('moves the period discount with the base it comes off, spending no further term', function () {
    $account = raAccount();
    $item = raItem($account, raPlan(1000));

    $applied = Meteric::applyDiscount($item, DiscountSpec::percent('10', 'TENOFF', terms: 3));
    Meteric::renew($item->subscription->fresh(), CarbonImmutable::parse('2026-07-02Z'));

    $july = Charge::where('line_group', $item->id)->whereRaw("lower(covers) = '2026-07-01 00:00:00+00'")->get();
    $discount = $july->firstWhere('kind', LineKind::Discount);

    expect((int) $discount->amount_minor)->toBe(-100);

    $termsUsed = $applied->fresh()->terms_used;

    Meteric::overridePrice($item, 600);
    Meteric::repriceAccrual($item->fresh()->setRelation('subscription', $item->subscription));

    expect((int) $discount->fresh()->amount_minor)->toBe(-60)
        ->and($applied->fresh()->terms_used)->toBe($termsUsed);
});
