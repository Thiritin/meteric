<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Meteric\Enums\DiscountState;
use Meteric\Enums\DiscountTarget;
use Meteric\Enums\LineKind;
use Meteric\Facades\Meteric;
use Meteric\Models\BillingAccount;
use Meteric\Models\Charge;
use Meteric\Models\Discount;
use Meteric\Models\Price;
use Meteric\Models\Product;
use Meteric\Models\Subscription;
use Meteric\Pricing\DiscountSpec;
use Meteric\Tax\TaxContext;

uses(RefreshDatabase::class);

function discountAccount(): BillingAccount
{
    return BillingAccount::create(['owner_type' => 'user', 'owner_id' => '1', 'currency' => 'EUR']);
}

function discountPrice(int $minor = 1000, int $setup = 0): Price
{
    $product = Product::create(['type' => 'vps', 'slug' => 'vps-'.uniqid(), 'name' => 'Hosting', 'pricing_model' => 'fixed']);

    return Price::create([
        'product_id' => $product->id, 'currency' => 'EUR', 'amount_minor' => $minor,
        'setup_fee_minor' => $setup,
        'pricing_model' => 'fixed', 'interval' => 'month', 'interval_count' => 1,
    ]);
}

function discountSubscription(BillingAccount $acc): Subscription
{
    return Subscription::create([
        'account_id' => $acc->id, 'customer_type' => 'user', 'customer_id' => '1',
        'currency' => 'EUR', 'state' => 'active',
    ]);
}

it('quotes a percentage discount as its own negative line and nets the total', function () {
    test()->travelTo(CarbonImmutable::parse('2026-06-01T00:00:00Z'));
    $price = discountPrice(1000);

    $quote = Meteric::createOrder()
        ->account(discountAccount())
        ->tax(new TaxContext(countryCode: 'DE'))
        ->add($price, 1, label: 'web1', group: 'l0')
        ->discount(DiscountSpec::percent('20', 'WELCOME20', terms: 3))
        ->quote();

    $discount = collect($quote->lines)->firstWhere('kind', LineKind::Discount);

    expect($discount->amount->getMinorAmount()->toInt())->toBe(-200)
        ->and($discount->tax->getMinorAmount()->toInt())->toBe(-38)
        ->and($quote->dueNowSubtotal->getMinorAmount()->toInt())->toBe(800)
        ->and($quote->dueNowTotal->getMinorAmount()->toInt())->toBe(952);
});

it('reports the regular price and the switchover date beside the discount', function () {
    test()->travelTo(CarbonImmutable::parse('2026-06-01T00:00:00Z'));

    $quote = Meteric::createOrder()
        ->account(discountAccount())
        ->add(discountPrice(1000), 1, label: 'web1', group: 'l0')
        ->discount(DiscountSpec::percent('20', 'WELCOME20', terms: 3))
        ->quote()
        ->toArray();

    // The first of the three terms is the one billed now, so two ongoing
    // periods are still discounted and the fourth runs at the regular price.
    expect($quote['recurring']['total_minor'])->toBe(1000)
        ->and($quote['recurring']['discount_minor'])->toBe(200)
        ->and($quote['recurring']['discount_until'])->toBe('2026-09-01T00:00:00+00:00');
});

it('leaves the switchover date open for a discount that runs for the life of the service', function () {
    test()->travelTo(CarbonImmutable::parse('2026-06-01T00:00:00Z'));

    $quote = Meteric::createOrder()
        ->account(discountAccount())
        ->add(discountPrice(1000), 1, label: 'web1', group: 'l0')
        ->discount(DiscountSpec::percent('100', 'FREEDOMAIN'))
        ->quote()
        ->toArray();

    expect($quote['recurring']['discount_minor'])->toBe(1000)
        ->and($quote['recurring']['discount_until'])->toBeNull();
});

it('discounts the setup fee without touching the recurring price', function () {
    test()->travelTo(CarbonImmutable::parse('2026-06-01T00:00:00Z'));

    $quote = Meteric::createOrder()
        ->account(discountAccount())
        ->add(discountPrice(1000, setup: 5000), 1, label: 'web1', group: 'l0')
        ->discount(DiscountSpec::percent('100', 'NOSETUP', target: DiscountTarget::Setup, terms: 1))
        ->quote();

    expect($quote->dueNowSubtotal->getMinorAmount()->toInt())->toBe(1000)
        ->and($quote->setupTotal()->getMinorAmount()->toInt())->toBe(5000)
        ->and($quote->recurringDiscountTotal()->getMinorAmount()->toInt())->toBe(0);
});

it('never takes off more than the line carries', function () {
    test()->travelTo(CarbonImmutable::parse('2026-06-01T00:00:00Z'));

    $quote = Meteric::createOrder()
        ->account(discountAccount())
        ->add(discountPrice(1000), 1, label: 'web1', group: 'l0')
        ->discount(DiscountSpec::fixed(9999, 'EUR', 'BIG'))
        ->quote();

    expect($quote->dueNowSubtotal->getMinorAmount()->toInt())->toBe(0)
        ->and($quote->dueNowTotal->getMinorAmount()->toInt())->toBe(0);
});

it('materializes a frozen discount as a standing row and a negative charge', function () {
    test()->travelTo(CarbonImmutable::parse('2026-06-01T00:00:00Z'));
    $acc = discountAccount();

    $order = Meteric::createOrder()
        ->account($acc)
        ->add(discountPrice(1000), 1, label: 'web1', group: 'l0')
        ->discount(DiscountSpec::percent('20', 'WELCOME20', terms: 3))
        ->create();

    $sub = discountSubscription($acc);
    $item = Meteric::materializeLine($order, 'l0', $sub);

    $discount = Discount::where('item_id', $item->id)->sole();
    $charge = Charge::where('kind', LineKind::Discount->value)->sole();

    expect($order->subtotal_minor)->toBe(800)
        ->and($discount->terms_used)->toBe(1)
        ->and($discount->state)->toBe(DiscountState::Active)
        ->and($charge->amount_minor)->toBe(-200)
        ->and($charge->line_group)->toBe($item->id)
        ->and($charge->description)->toBe('WELCOME20');
});

it('spends one term per renewal and stops when the terms run out', function () {
    test()->travelTo(CarbonImmutable::parse('2026-06-01T00:00:00Z'));
    $acc = discountAccount();

    $order = Meteric::createOrder()
        ->account($acc)
        ->add(discountPrice(1000), 1, label: 'web1', group: 'l0')
        ->discount(DiscountSpec::percent('20', 'WELCOME20', terms: 3))
        ->create();

    $sub = discountSubscription($acc);
    $item = Meteric::materializeLine($order, 'l0', $sub);

    Meteric::renew($sub->refresh(), CarbonImmutable::parse('2026-07-01T00:00:00Z'));
    Meteric::renew($sub->refresh(), CarbonImmutable::parse('2026-08-01T00:00:00Z'));
    Meteric::renew($sub->refresh(), CarbonImmutable::parse('2026-09-01T00:00:00Z'));

    $discounts = Charge::where('kind', LineKind::Discount->value)->get();

    expect($discounts)->toHaveCount(3)
        ->and($discounts->sum('amount_minor'))->toBe(-600)
        ->and(Discount::where('item_id', $item->id)->sole()->state)->toBe(DiscountState::Exhausted);
});

it('runs for the life of the item when no terms are set', function () {
    test()->travelTo(CarbonImmutable::parse('2026-06-01T00:00:00Z'));
    $acc = discountAccount();
    $sub = discountSubscription($acc);

    $order = Meteric::createOrder()->account($acc)->add(discountPrice(1000), 1, label: 'web1', group: 'l0')->create();
    $item = Meteric::materializeLine($order, 'l0', $sub);

    Meteric::applyDiscount($item, DiscountSpec::percent('50', 'HALF'));

    Meteric::renew($sub->refresh(), CarbonImmutable::parse('2026-07-01T00:00:00Z'));
    Meteric::renew($sub->refresh(), CarbonImmutable::parse('2026-08-01T00:00:00Z'));

    expect((int) Charge::where('kind', LineKind::Discount->value)->sum('amount_minor'))->toBe(-1000)
        ->and(Discount::where('item_id', $item->id)->sole()->state)->toBe(DiscountState::Active);
});

it('stops discounting once cancelled', function () {
    test()->travelTo(CarbonImmutable::parse('2026-06-01T00:00:00Z'));
    $acc = discountAccount();
    $sub = discountSubscription($acc);

    $order = Meteric::createOrder()->account($acc)->add(discountPrice(1000), 1, label: 'web1', group: 'l0')->create();
    $item = Meteric::materializeLine($order, 'l0', $sub);
    $discount = Meteric::applyDiscount($item, DiscountSpec::percent('50', 'HALF'));

    Meteric::renew($sub->refresh(), CarbonImmutable::parse('2026-07-01T00:00:00Z'));
    Meteric::cancelDiscount($discount);
    Meteric::renew($sub->refresh(), CarbonImmutable::parse('2026-08-01T00:00:00Z'));

    expect(Charge::where('kind', LineKind::Discount->value)->count())->toBe(1)
        ->and($discount->fresh()->state)->toBe(DiscountState::Canceled);
});

it('nests the discount under the line it reduces and reduces that invoice\'s tax', function () {
    test()->travelTo(CarbonImmutable::parse('2026-06-01T00:00:00Z'));
    $acc = BillingAccount::create([
        'owner_type' => 'user', 'owner_id' => '1', 'currency' => 'EUR',
        'tax_profile' => ['country' => 'DE'],
    ]);

    $order = Meteric::createOrder()
        ->account($acc)
        ->add(discountPrice(1000), 1, label: 'web1', group: 'l0')
        ->discount(DiscountSpec::percent('20', 'WELCOME20', terms: 3))
        ->create();

    $sub = discountSubscription($acc);
    $item = Meteric::materializeLine($order, 'l0', $sub);
    $invoice = Meteric::invoicePending($acc);

    $line = $invoice->lines()->where('kind', LineKind::Discount->value)->sole();

    expect($line->parent_id)->not->toBeNull()
        ->and($line->amount_minor)->toBe(-200)
        ->and($line->tax_minor)->toBe(-38)
        ->and($invoice->subtotal_minor)->toBe(800)
        ->and($invoice->tax_minor)->toBe(152)
        ->and($invoice->total_minor)->toBe(952)
        ->and($item->discounts()->count())->toBe(1);
});
