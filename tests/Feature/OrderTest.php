<?php

declare(strict_types=1);

use Brick\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Meteric\Enums\FirstPeriodPolicy;
use Meteric\Enums\InvoiceState;
use Meteric\Enums\OrderState;
use Meteric\Enums\PricingModel;
use Meteric\Enums\SubscriptionState;
use Meteric\Events\OrderCanceled;
use Meteric\Events\OrderCreated;
use Meteric\Events\OrderExpired;
use Meteric\Events\OrderPaid;
use Meteric\Events\SubscriptionStarted;
use Meteric\Facades\Meteric;
use Meteric\Models\Addon;
use Meteric\Models\BillingAccount;
use Meteric\Models\Charge;
use Meteric\Models\Invoice;
use Meteric\Models\ItemOption;
use Meteric\Models\Order;
use Meteric\Models\Price;
use Meteric\Models\Product;
use Meteric\Models\Subscription;

uses(RefreshDatabase::class);

function orderAccount(): BillingAccount
{
    return BillingAccount::create(['owner_type' => 'user', 'owner_id' => '1', 'currency' => 'EUR']);
}

function orderMonthlyPrice(int $minor = 1000): Price
{
    $product = Product::create(['type' => 'vps', 'slug' => 'vps-'.uniqid(), 'name' => 'Hosting', 'pricing_model' => 'fixed']);

    return Price::create([
        'product_id' => $product->id, 'currency' => 'EUR', 'amount_minor' => $minor,
        'pricing_model' => 'fixed', 'interval' => 'month', 'interval_count' => 1,
    ]);
}

function orderYearlyPrice(int $minor = 12000): Price
{
    $product = Product::create(['type' => 'domain', 'slug' => 'domain-'.uniqid(), 'name' => 'Domain', 'pricing_model' => 'fixed']);

    return Price::create([
        'product_id' => $product->id, 'currency' => 'EUR', 'amount_minor' => $minor,
        'pricing_model' => 'fixed', 'interval' => 'year', 'interval_count' => 1,
    ]);
}

function orderOptionPrice(int $minor = 500): Price
{
    $product = Product::create(['type' => 'ram', 'slug' => 'ram-'.uniqid(), 'name' => 'RAM', 'pricing_model' => 'fixed']);

    return Price::create([
        'product_id' => $product->id, 'currency' => 'EUR', 'amount_minor' => $minor,
        'pricing_model' => 'fixed', 'interval' => 'month', 'interval_count' => 1,
    ]);
}

function orderRelativeAddonPrice(float $percent = 20): Price
{
    $product = Product::create(['type' => 'backup', 'slug' => 'backup-'.uniqid(), 'name' => 'Backups', 'pricing_model' => 'relative']);

    return Price::create([
        'product_id' => $product->id, 'currency' => 'EUR', 'amount_minor' => 0,
        'pricing_model' => PricingModel::Relative->value, 'interval' => 'month', 'interval_count' => 1,
        'percent' => $percent,
    ]);
}

it('opens a multi-item order without billing anything', function () {
    Event::fake([OrderCreated::class]);
    test()->travelTo(CarbonImmutable::parse('2026-06-01T00:00:00Z'));

    $acc = orderAccount();
    $hosting = orderMonthlyPrice(1000);
    $domain = orderYearlyPrice(12000);

    $order = Meteric::createOrder()
        ->account($acc)
        ->firstPeriod(FirstPeriodPolicy::FullPeriod)
        ->add($hosting, 1, label: 'web1')
        ->add($domain, 1, label: 'example.com')
        ->create();

    expect($order->state)->toBe(OrderState::Pending)
        ->and($order->contents)->toHaveCount(2)
        ->and(Subscription::count())->toBe(0)
        ->and(Invoice::count())->toBe(0)
        ->and(Charge::count())->toBe(0);

    $sumOfLines = array_sum(array_column($order->contents, 'amount_minor'));
    expect($sumOfLines)->toBe(13000)
        ->and($order->subtotal_minor)->toBe(13000)
        ->and($order->total_minor)->toBe($order->subtotal_minor + $order->tax_minor);

    Event::assertDispatched(OrderCreated::class);
});

it('pays an order in full and materializes a subscription + paid invoice', function () {
    Event::fake([OrderPaid::class, SubscriptionStarted::class]);
    test()->travelTo(CarbonImmutable::parse('2026-06-01T00:00:00Z'));

    $acc = orderAccount();
    $order = Meteric::createOrder()
        ->account($acc)
        ->firstPeriod(FirstPeriodPolicy::FullPeriod)
        ->add(orderMonthlyPrice(1000), 1, label: 'web1')
        ->add(orderYearlyPrice(12000), 1, label: 'example.com')
        ->create();

    $paid = Meteric::payOrder($order, Money::ofMinor($order->total_minor, 'EUR'), 'pi_123');

    expect($paid->state)->toBe(OrderState::Converted)
        ->and($paid->subscription_id)->not->toBeNull()
        ->and($paid->invoice_id)->not->toBeNull();

    $sub = Subscription::findOrFail($paid->subscription_id);
    expect($sub->state)->toBe(SubscriptionState::Active)
        ->and($sub->items()->count())->toBe(2);

    $invoice = Invoice::findOrFail($paid->invoice_id);
    expect($invoice->state)->toBe(InvoiceState::Paid)
        ->and($invoice->subtotal_minor)->toBe(13000);

    Event::assertDispatched(SubscriptionStarted::class);
    Event::assertDispatched(OrderPaid::class);
});

it('freezes amounts so a later price change does not move a pending order', function () {
    test()->travelTo(CarbonImmutable::parse('2026-06-01T00:00:00Z'));

    $acc = orderAccount();
    $price = orderMonthlyPrice(1000);

    $order = Meteric::createOrder()
        ->account($acc)
        ->firstPeriod(FirstPeriodPolicy::FullPeriod)
        ->add($price, 1, label: 'web1')
        ->create();

    // Catalog price doubles after the order was frozen.
    $price->forceFill(['amount_minor' => 2000])->save();

    $paid = Meteric::payOrder($order, Money::ofMinor($order->total_minor, 'EUR'));
    $invoice = Invoice::findOrFail($paid->invoice_id);

    expect($order->subtotal_minor)->toBe(1000)
        ->and($invoice->subtotal_minor)->toBe(1000);
});

it('expires a pending order past its ttl and is idempotent', function () {
    Event::fake([OrderExpired::class]);
    test()->travelTo(CarbonImmutable::parse('2026-06-01T00:00:00Z'));

    $acc = orderAccount();
    $order = Meteric::createOrder()
        ->account($acc)
        ->expiresIn(60)
        ->firstPeriod(FirstPeriodPolicy::FullPeriod)
        ->add(orderMonthlyPrice(1000), 1, label: 'web1')
        ->create();

    test()->travelTo(CarbonImmutable::parse('2026-06-01T02:00:00Z'));
    $this->artisan('meteric:run')->assertSuccessful();

    expect(Order::findOrFail($order->id)->state)->toBe(OrderState::Expired)
        ->and(Subscription::count())->toBe(0);
    Event::assertDispatchedTimes(OrderExpired::class, 1);

    // Second sweep is a no-op.
    $again = Meteric::expireOrders();
    expect($again)->toBe(0);
});

it('is idempotent: paying twice yields exactly one subscription and invoice', function () {
    test()->travelTo(CarbonImmutable::parse('2026-06-01T00:00:00Z'));

    $acc = orderAccount();
    $order = Meteric::createOrder()
        ->account($acc)
        ->firstPeriod(FirstPeriodPolicy::FullPeriod)
        ->add(orderMonthlyPrice(1000), 1, label: 'web1')
        ->create();

    $amount = Money::ofMinor($order->total_minor, 'EUR');
    Meteric::payOrder($order, $amount);
    $second = Meteric::payOrder($order->fresh(), $amount);

    expect($second->state)->toBe(OrderState::Converted)
        ->and(Subscription::count())->toBe(1)
        ->and(Invoice::count())->toBe(1);
});

it('rejects a partial payment below the total', function () {
    test()->travelTo(CarbonImmutable::parse('2026-06-01T00:00:00Z'));

    $acc = orderAccount();
    $order = Meteric::createOrder()
        ->account($acc)
        ->firstPeriod(FirstPeriodPolicy::FullPeriod)
        ->add(orderMonthlyPrice(1000), 1, label: 'web1')
        ->create();

    expect(fn () => Meteric::payOrder($order, Money::ofMinor(500, 'EUR')))
        ->toThrow(InvalidArgumentException::class);

    expect(Subscription::count())->toBe(0)
        ->and(Invoice::count())->toBe(0);
});

it('cancels a pending order and rejects a later payment', function () {
    Event::fake([OrderCanceled::class]);
    test()->travelTo(CarbonImmutable::parse('2026-06-01T00:00:00Z'));

    $acc = orderAccount();
    $order = Meteric::createOrder()
        ->account($acc)
        ->firstPeriod(FirstPeriodPolicy::FullPeriod)
        ->add(orderMonthlyPrice(1000), 1, label: 'web1')
        ->create();

    $canceled = Meteric::cancelOrder($order);
    expect($canceled->state)->toBe(OrderState::Canceled);
    Event::assertDispatched(OrderCanceled::class);

    expect(fn () => Meteric::payOrder($canceled, Money::ofMinor(1000, 'EUR')))
        ->toThrow(LogicException::class);
});

it('survives option value and label through to a materialized ItemOption', function () {
    test()->travelTo(CarbonImmutable::parse('2026-06-01T00:00:00Z'));

    $acc = orderAccount();
    $optPrice = orderOptionPrice(500);

    $order = Meteric::createOrder()
        ->account($acc)
        ->firstPeriod(FirstPeriodPolicy::FullPeriod)
        ->add(orderMonthlyPrice(1000), 1, label: 'web1')
        ->option('ram', '1024', 'quantity', $optPrice, 1, label: '1 GB RAM')
        ->create();

    $paid = Meteric::payOrder($order, Money::ofMinor($order->total_minor, 'EUR'));

    $option = ItemOption::query()->where('key', 'ram')->firstOrFail();
    expect($option->value)->toBe('1024')
        ->and($option->label)->toBe('1 GB RAM')
        ->and($order->subtotal_minor)->toBe(1500)
        ->and(Invoice::findOrFail($paid->invoice_id)->subtotal_minor)->toBe(1500);
});

it('freezes a relative addon amount across a base price change', function () {
    test()->travelTo(CarbonImmutable::parse('2026-06-01T00:00:00Z'));

    $acc = orderAccount();
    $base = orderMonthlyPrice(1000);
    $backups = orderRelativeAddonPrice(20); // 20% of base

    $order = Meteric::createOrder()
        ->account($acc)
        ->firstPeriod(FirstPeriodPolicy::FullPeriod)
        ->add($base, 1, label: 'web1')
        ->addon($backups)
        ->create();

    // Frozen: base 1000 + 20% addon (200) = 1200.
    expect($order->subtotal_minor)->toBe(1200)
        ->and($order->contents[0]['addons'][0]['amount_minor'])->toBe(200);

    // Base price triples mid-flight; the frozen addon must not follow.
    $base->forceFill(['amount_minor' => 3000])->save();

    $paid = Meteric::payOrder($order, Money::ofMinor($order->total_minor, 'EUR'));

    $addon = Addon::query()->firstOrFail();
    $addonCharge = Charge::query()->where('origin_id', $addon->id)->firstOrFail();
    expect($addonCharge->amount_minor)->toBe(200)
        ->and(Invoice::findOrFail($paid->invoice_id)->subtotal_minor)->toBe(1200);
});
