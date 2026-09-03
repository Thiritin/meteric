<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Meteric\Enums\LineKind;
use Meteric\Enums\PriceScope;
use Meteric\Enums\UpgradePolicy;
use Meteric\Facades\Meteric;
use Meteric\Models\BillingAccount;
use Meteric\Models\Charge;
use Meteric\Models\Price;
use Meteric\Models\Product;
use Meteric\Models\SubscriptionItem;

uses(RefreshDatabase::class);

function poAccount(): BillingAccount
{
    return BillingAccount::create(['owner_type' => 'user', 'owner_id' => '1', 'currency' => 'EUR']);
}

function poPlan(int $minor): Price
{
    $product = Product::create(['type' => 'vps', 'slug' => 'po-'.uniqid(), 'name' => 'VPS '.$minor, 'pricing_model' => 'fixed']);

    return Price::create([
        'product_id' => $product->id, 'currency' => 'EUR', 'amount_minor' => $minor,
        'pricing_model' => 'fixed', 'interval' => 'month', 'interval_count' => 1, 'billing_mode' => 'in_advance',
    ]);
}

function poItem(BillingAccount $account, Price $base): SubscriptionItem
{
    $subscription = Meteric::subscribe()->account($account)->at(CarbonImmutable::parse('2026-06-01Z'))
        ->add($base, 1, null, label: 'web1.example')->create();

    return $subscription->items->first()->setRelation('subscription', $subscription);
}

it('bills the override instead of the price the product publishes', function () {
    $item = poItem(poAccount(), poPlan(1000));

    Meteric::overridePrice($item, 600);

    expect($item->fresh()->periodAmount()->getMinorAmount()->toInt())->toBe(600);

    Meteric::renew($item->subscription->fresh(), CarbonImmutable::parse('2026-07-02Z'));

    $july = Charge::where('subscription_id', $item->subscription_id)
        ->whereRaw("lower(covers) = '2026-07-01 00:00:00+00'")
        ->where('kind', LineKind::Recurring->value)
        ->first();

    expect((int) $july->amount_minor)->toBe(600);
});

// The acceptance test for the whole shape: the prorator has to read the
// override, not the catalog price. It does so without knowing overrides exist,
// because `$item->price` resolves to one - which is the property that made this
// design preferable to an amount column read at two dozen call sites.
it('prorates against the override rather than the published price', function () {
    $account = poAccount();
    $base = poPlan(1000);
    $item = poItem($account, $base);

    Meteric::overridePrice($item, 600);

    $bigger = poPlan(3000);

    Meteric::changePlan(
        $item->fresh()->setRelation('subscription', $item->subscription),
        $bigger,
        upgrade: UpgradePolicy::Prorate,
        at: CarbonImmutable::parse('2026-06-16Z'),
    );

    // Half a 30-day June left: the unused credit is half of 600, not half of
    // the 1000 the catalog publishes. Credits are stored negative.
    $credit = Charge::where('subscription_id', $item->subscription_id)
        ->where('kind', LineKind::Credit->value)
        ->orderByDesc('created_at')
        ->first();

    expect((int) $credit->amount_minor)->toBe(-300);
});

it('drops the override when the item moves onto another price', function () {
    $account = poAccount();
    $item = poItem($account, poPlan(1000));

    Meteric::overridePrice($item, 600);

    expect($item->fresh()->hasPriceOverride())->toBeTrue();

    Meteric::changePlan(
        $item->fresh()->setRelation('subscription', $item->subscription),
        poPlan(3000),
        at: CarbonImmutable::parse('2026-06-16Z'),
    );

    expect($item->fresh()->hasPriceOverride())->toBeFalse()
        ->and($item->fresh()->periodAmount()->getMinorAmount()->toInt())->toBe(3000);
});

it('keeps the override row when the override is cleared', function () {
    $item = poItem(poAccount(), poPlan(1000));

    Meteric::overridePrice($item, 600);
    $overrideId = $item->fresh()->price_override_id;

    Meteric::clearPriceOverride($item->fresh());

    // Kept, never deleted: a charge or an invoice line already written against
    // it has to resolve years later.
    expect(Price::find($overrideId))->not->toBeNull()
        ->and($item->fresh()->hasPriceOverride())->toBeFalse()
        ->and($item->fresh()->periodAmount()->getMinorAmount()->toInt())->toBe(1000);
});

it('never offers an override as a price the product sells at', function () {
    $base = poPlan(1000);
    $item = poItem(poAccount(), $base);

    Meteric::overridePrice($item, 600);

    $override = Price::find($item->fresh()->price_override_id);

    expect($override->scope)->toBe(PriceScope::Override)
        ->and($override->product_id)->toBe($base->product_id)
        ->and($base->product->priceFor('EUR')->id)->toBe($base->id)
        ->and($base->product->prices()->catalog()->pluck('id')->all())->toBe([$base->id]);
});
