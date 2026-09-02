<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Meteric\Enums\FirstPeriodPolicy;
use Meteric\Enums\ItemState;
use Meteric\Enums\LineKind;
use Meteric\Exceptions\PeriodNotRebasable;
use Meteric\Facades\Meteric;
use Meteric\Models\BillingAccount;
use Meteric\Models\Charge;
use Meteric\Models\Price;
use Meteric\Models\Product;
use Meteric\Models\Subscription;
use Meteric\Models\SubscriptionItem;

uses(RefreshDatabase::class);

function rebasePrice(int $minor = 3000, string $interval = 'month'): Price
{
    $product = Product::create(['type' => 'vps', 'slug' => 'rb-'.uniqid(), 'name' => 'VPS', 'pricing_model' => 'fixed']);

    return Price::create([
        'product_id' => $product->id, 'currency' => 'EUR', 'amount_minor' => $minor,
        'pricing_model' => 'fixed', 'interval' => $interval, 'interval_count' => 1,
    ]);
}

/** A subscription on a 30-day month (June 2026) at 30.00, so one day is 1.00. */
function rebaseSub(int $minor = 3000): Subscription
{
    $acc = BillingAccount::create(['owner_type' => 'user', 'owner_id' => '1', 'currency' => 'EUR']);

    return Meteric::subscribe()->account($acc)
        ->firstPeriod(FirstPeriodPolicy::FullPeriod)
        ->at(CarbonImmutable::parse('2026-06-01T00:00:00Z'))
        ->add(rebasePrice($minor))
        ->create();
}

it('extends a period and charges the span at the full rate', function () {
    $sub = rebaseSub();
    $item = $sub->items()->first();

    $item = Meteric::rebasePeriod($item, CarbonImmutable::parse('2026-07-11T00:00:00Z'), prorate: true);

    $charge = Charge::where('kind', LineKind::Prorated->value)->firstOrFail();
    expect($item->current_period->start->toIso8601String())->toBe('2026-06-01T00:00:00+00:00')
        ->and($item->current_period->end->toIso8601String())->toBe('2026-07-11T00:00:00+00:00')
        ->and($sub->fresh()->current_period->end->toIso8601String())->toBe('2026-07-11T00:00:00+00:00')
        // 10 days of the 31-day July cycle at 30.00 = 9.68.
        ->and($charge->amount_minor)->toBe(968)
        ->and($charge->description)->toBe('Extended VPS')
        ->and(Charge::count())->toBe(2);
});

it('shortens a period and credits the span', function () {
    $sub = rebaseSub();
    $item = $sub->items()->first();

    Meteric::rebasePeriod($item, CarbonImmutable::parse('2026-06-21T00:00:00Z'), prorate: true);

    $credit = Charge::where('kind', LineKind::Credit->value)->firstOrFail();
    expect($credit->amount_minor)->toBe(-1000)
        ->and($credit->description)->toBe('Shortened VPS')
        ->and($item->fresh()->current_period->end->toIso8601String())->toBe('2026-06-21T00:00:00+00:00')
        ->and($sub->fresh()->current_period->end->toIso8601String())->toBe('2026-06-21T00:00:00+00:00');
});

it('moves the dates without money when not prorating', function () {
    $sub = rebaseSub();
    $item = $sub->items()->first();

    Meteric::rebasePeriod($item, CarbonImmutable::parse('2026-08-01T00:00:00Z'));

    expect($item->fresh()->current_period->end->toIso8601String())->toBe('2026-08-01T00:00:00+00:00')
        ->and(Charge::count())->toBe(1);
});

it('prices whole extra cycles plus a remainder', function () {
    $sub = rebaseSub();
    $item = $sub->items()->first();

    // July 1 to Sept 16: July (31 days, full) + August (31 days, full) + 15 of September's 30 days.
    $preview = Meteric::previewRebase($item, CarbonImmutable::parse('2026-09-16T00:00:00Z'));

    expect($preview->kind)->toBe(LineKind::Prorated)
        ->and($preview->amount->getMinorAmount()->toInt())->toBe(3000 + 3000 + 1500);
});

it('previews exactly what rebasePeriod writes', function () {
    $sub = rebaseSub();
    $item = $sub->items()->first();
    $newEnd = CarbonImmutable::parse('2026-07-19T12:00:00Z');

    $preview = Meteric::previewRebase($item, $newEnd);
    expect(Charge::count())->toBe(1)
        ->and($item->fresh()->current_period->end->toIso8601String())->toBe('2026-07-01T00:00:00+00:00');

    Meteric::rebasePeriod($item, $newEnd, prorate: true);
    $charge = Charge::where('kind', LineKind::Prorated->value)->firstOrFail();

    expect($charge->amount_minor)->toBe($preview->amount->getMinorAmount()->toInt())
        ->and($preview->kind)->toBe(LineKind::Prorated)
        ->and($preview->period->toArray())->toBe($item->fresh()->current_period->toArray())
        ->and($preview->toArray()['amount_minor'])->toBe($charge->amount_minor);

    $same = Meteric::previewRebase($item->fresh(), $newEnd);
    expect($same->kind)->toBeNull()
        ->and($same->amount->isZero())->toBeTrue();
});

it('follows the earliest active item on the subscription', function () {
    $acc = BillingAccount::create(['owner_type' => 'user', 'owner_id' => '1', 'currency' => 'EUR']);
    $sub = Meteric::subscribe()->account($acc)
        ->firstPeriod(FirstPeriodPolicy::FullPeriod)
        ->at(CarbonImmutable::parse('2026-06-01T00:00:00Z'))
        ->add(rebasePrice(3000))
        ->add(rebasePrice(12000, 'year'))
        ->create();
    $monthly = $sub->items()->whereHas('price', fn ($q) => $q->where('interval', 'month'))->firstOrFail();

    Meteric::rebasePeriod($monthly, CarbonImmutable::parse('2028-01-01T00:00:00Z'));

    expect($sub->fresh()->current_period->end->toIso8601String())->toBe('2027-06-01T00:00:00+00:00');
});

it('refuses an end at or before the start, a canceled item and a one-off item', function () {
    $sub = rebaseSub();
    $item = $sub->items()->first();

    expect(fn () => Meteric::rebasePeriod($item, CarbonImmutable::parse('2026-06-01T00:00:00Z')))
        ->toThrow(PeriodNotRebasable::class, 'not after');
    expect(fn () => Meteric::previewRebase($item, CarbonImmutable::parse('2026-05-01T00:00:00Z')))
        ->toThrow(PeriodNotRebasable::class, 'not after');

    $item->forceFill(['state' => ItemState::Canceled])->save();
    expect(fn () => Meteric::rebasePeriod($item->fresh(), CarbonImmutable::parse('2026-08-01T00:00:00Z'), true))
        ->toThrow(PeriodNotRebasable::class, 'only an active item');

    $oneOff = Price::create(['product_id' => $item->price->product_id, 'currency' => 'EUR', 'amount_minor' => 500, 'pricing_model' => 'one_off', 'purpose' => 'setup']);
    $sub2 = Meteric::subscribe()->account($sub->account)->at(CarbonImmutable::parse('2026-06-01T00:00:00Z'))->add($oneOff)->create();
    $setup = $sub2->items()->first();
    expect(fn () => Meteric::rebasePeriod($setup, CarbonImmutable::parse('2026-08-01T00:00:00Z')))
        ->toThrow(PeriodNotRebasable::class, 'not recurring');

    expect(Charge::where('kind', LineKind::Prorated->value)->count())->toBe(0)
        ->and(SubscriptionItem::findOrFail($item->id)->current_period->end->toIso8601String())->toBe('2026-07-01T00:00:00+00:00');
});
