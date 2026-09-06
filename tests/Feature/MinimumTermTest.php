<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Meteric\Enums\SubscriptionState;
use Meteric\Exceptions\WithinMinimumTerm;
use Meteric\Facades\Meteric;
use Meteric\Models\BillingAccount;
use Meteric\Models\Price;
use Meteric\Models\Product;
use Meteric\Models\Subscription;

uses(RefreshDatabase::class);

beforeEach(fn () => test()->travelTo(CarbonImmutable::parse('2026-06-01Z')));

function minTermAccount(): BillingAccount
{
    return BillingAccount::create(['owner_type' => 'user', 'owner_id' => '1', 'currency' => 'EUR']);
}

function minTermProduct(int $termPeriods = 0, int $noticeDays = 0): Product
{
    return Product::create([
        'type' => 'colocation', 'slug' => 'term-'.uniqid(), 'name' => 'Rack', 'pricing_model' => 'fixed',
        'config' => array_filter([
            'cancel_notice_days' => $noticeDays,
            'minimum_term_periods' => $termPeriods,
        ]),
    ]);
}

function minTermPrice(Product $product, int $minor = 1000, ?int $override = null, string $interval = 'month', int $count = 1): Price
{
    return Price::create([
        'product_id' => $product->id, 'currency' => 'EUR', 'amount_minor' => $minor,
        'pricing_model' => 'fixed', 'interval' => $interval, 'interval_count' => $count,
        'minimum_term_periods' => $override,
    ]);
}

function minTermSub(BillingAccount $acc, Price $price): Subscription
{
    return Meteric::subscribe()->account($acc)->at(CarbonImmutable::parse('2026-06-01Z'))->add($price, 1)->create();
}

it('takes the term from the product and overrides it on the price', function () {
    $product = minTermProduct(12);
    $inherited = minTermPrice($product);
    $yearly = minTermPrice($product, 10000, override: 1, interval: 'year');

    expect($inherited->minimumTerm())->toBe(12)
        ->and($yearly->minimumTerm())->toBe(1)
        ->and($inherited->minimumTermEnd(CarbonImmutable::parse('2026-06-01Z'))->toDateString())->toBe('2027-06-01')
        ->and($yearly->minimumTermEnd(CarbonImmutable::parse('2026-06-01Z'))->toDateString())->toBe('2027-06-01');
});

it('refuses a negative term on the product config', function () {
    expect(fn () => Product::create([
        'type' => 'vps', 'slug' => 'term-bad-'.uniqid(), 'name' => 'VPS', 'pricing_model' => 'fixed',
        'config' => ['minimum_term_periods' => -1],
    ]))->toThrow(InvalidArgumentException::class);
});

it('freezes the agreed term onto the item at signup', function () {
    $sub = minTermSub(minTermAccount(), minTermPrice(minTermProduct(12)));
    $item = $sub->items()->first();

    expect($item->minimum_term_periods)->toBe(12)
        ->and($item->committed_until->toDateString())->toBe('2027-06-01')
        ->and(Meteric::committedUntil($sub)->toDateString())->toBe('2027-06-01');
});

it('leaves an item sold without a term uncommitted', function () {
    $sub = minTermSub(minTermAccount(), minTermPrice(minTermProduct()));

    expect($sub->items()->first()->committed_until)->toBeNull()
        ->and(Meteric::committedUntil($sub))->toBeNull();
});

it('does not move a contract already sold when the product is edited', function () {
    $product = minTermProduct();
    $sub = minTermSub(minTermAccount(), minTermPrice($product));

    $product->forceFill(['config' => ['minimum_term_periods' => 12]])->save();

    expect(Meteric::committedUntil($sub->fresh()))->toBeNull();
    expect(Meteric::cancellationOptions($sub->fresh(), 1)[0]->toDateString())->toBe('2026-07-01');
});

it('offers no boundary inside the term', function () {
    $sub = minTermSub(minTermAccount(), minTermPrice(minTermProduct(12)));

    $options = Meteric::cancellationOptions($sub, 3);

    expect($options)->toHaveCount(3)
        ->and($options[0]->toDateString())->toBe('2027-06-01')
        ->and($options[1]->toDateString())->toBe('2027-07-01');
});

it('applies the notice period to the first offered boundary, not to the term start', function () {
    // 30 days notice on a term ending 2027-06-01: on 2026-06-01 the cutoff for
    // that boundary is 2027-05-02, which is still ahead, so it is offered.
    $sub = minTermSub(minTermAccount(), minTermPrice(minTermProduct(12, noticeDays: 30)));

    expect(Meteric::cancellationOptions($sub, 1)[0]->toDateString())->toBe('2027-06-01');

    // Standing inside the notice window of the term end pushes it one period on.
    test()->travelTo(CarbonImmutable::parse('2027-05-20Z'));
    expect(Meteric::cancellationOptions($sub->fresh(), 1)[0]->toDateString())->toBe('2027-07-01');
});

it('refuses a cancellation inside the term and names the earliest date allowed', function () {
    $sub = minTermSub(minTermAccount(), minTermPrice(minTermProduct(12)));

    expect(fn () => Meteric::cancel($sub, 'period_end'))
        ->toThrow(WithinMinimumTerm::class, 'The earliest date allowed is 2027-06-01.');

    expect($sub->fresh()->cancel_at)->toBeNull();
});

it('allows a cancellation to the term boundary', function () {
    $sub = minTermSub(minTermAccount(), minTermPrice(minTermProduct(12)));

    Meteric::cancel($sub, Meteric::cancellationOptions($sub, 1)[0]);

    expect($sub->fresh()->cancel_at->toDateString())->toBe('2027-06-01');
});

it('still terminates immediately inside the term', function () {
    $sub = minTermSub(minTermAccount(), minTermPrice(minTermProduct(12)));

    Meteric::cancel($sub, 'now');

    expect($sub->fresh()->state)->toBe(SubscriptionState::Canceled);
});

it('refuses a cheaper plan inside the term and allows a dearer one', function () {
    $product = minTermProduct(12);
    $sub = minTermSub(minTermAccount(), minTermPrice($product, 2000));
    $item = $sub->items()->first();

    $cheaper = minTermPrice(minTermProduct(), 1000);
    $dearer = minTermPrice(minTermProduct(), 5000);

    expect(fn () => Meteric::changePlan($item, $cheaper))->toThrow(WithinMinimumTerm::class);

    Meteric::changePlan($item->fresh(), $dearer);

    expect($item->fresh()->price_id)->toBe($dearer->id)
        ->and($item->fresh()->committed_until->toDateString())->toBe('2027-06-01');
});
