<?php

declare(strict_types=1);

use Brick\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Meteric\Enums\FirstPeriodPolicy;
use Meteric\Enums\InvoiceState;
use Meteric\Enums\LineKind;
use Meteric\Enums\OrderState;
use Meteric\Enums\PricingModel;
use Meteric\Enums\SubscriptionState;
use Meteric\Events\OrderCanceled;
use Meteric\Events\OrderCreated;
use Meteric\Events\OrderExpired;
use Meteric\Events\OrderPaid;
use Meteric\Events\SubscriptionStarted;
use Meteric\Exceptions\LineNotMaterializable;
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
use Meteric\Tax\TaxContext;

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

function orderSetupPrice(int $minor = 1000, int $setup = 2500): Price
{
    $product = Product::create(['type' => 'dedicated', 'slug' => 'ded-'.uniqid(), 'name' => 'Dedicated', 'pricing_model' => 'fixed']);

    return Price::create([
        'product_id' => $product->id, 'currency' => 'EUR', 'amount_minor' => $minor, 'setup_fee_minor' => $setup,
        'pricing_model' => 'fixed', 'interval' => 'month', 'interval_count' => 1,
    ]);
}

it('freezes the base setup fee and charges it once at checkout', function () {
    test()->travelTo(CarbonImmutable::parse('2026-06-01T00:00:00Z'));

    $acc = orderAccount();
    $order = Meteric::createOrder()
        ->account($acc)
        ->firstPeriod(FirstPeriodPolicy::FullPeriod)
        ->add(orderSetupPrice(1000, 2500), 1, label: 'srv1')
        ->create();

    expect($order->contents[0]['amount_minor'])->toBe(1000)
        ->and($order->contents[0]['setup_minor'])->toBe(2500)
        ->and($order->subtotal_minor)->toBe(3500)
        ->and($order->recurring_total_minor)->toBe(1000)
        ->and($order->quote_snapshot['due_now']['setup_minor'])->toBe(2500)
        ->and(array_column($order->quote_snapshot['lines'], 'kind'))->toBe(['recurring', 'setup']);

    $paid = Meteric::payOrder($order, Money::ofMinor($order->total_minor, 'EUR'));

    $setups = Charge::where('kind', LineKind::Setup->value)->get();
    expect($setups)->toHaveCount(1)
        ->and($setups->first()->amount_minor)->toBe(2500)
        ->and($setups->first()->covers)->toBeNull()
        ->and(Invoice::findOrFail($paid->invoice_id)->subtotal_minor)->toBe(3500);

    // The next cycle bills the recurring amount only.
    Meteric::renew(Subscription::findOrFail($paid->subscription_id), CarbonImmutable::parse('2026-07-01T00:00:00Z'));

    expect(Charge::where('kind', LineKind::Setup->value)->count())->toBe(1)
        ->and(Charge::whereRaw("lower(covers) = '2026-07-01 00:00:00+00'")->sum('amount_minor'))->toEqual(1000);
});

it('owes no setup on an order frozen before setup_minor existed', function () {
    test()->travelTo(CarbonImmutable::parse('2026-06-01T00:00:00Z'));

    $acc = orderAccount();
    $order = Meteric::createOrder()
        ->account($acc)
        ->firstPeriod(FirstPeriodPolicy::FullPeriod)
        ->add(orderSetupPrice(1000, 2500), 1, label: 'srv1')
        ->create();

    $contents = $order->contents;
    unset($contents[0]['setup_minor']);
    $order->forceFill(['contents' => $contents, 'subtotal_minor' => 1000, 'total_minor' => 1000, 'tax_minor' => 0])->save();

    $paid = Meteric::payOrder($order->fresh(), Money::ofMinor(1000, 'EUR'));

    expect(Charge::where('kind', LineKind::Setup->value)->count())->toBe(0)
        ->and(Invoice::findOrFail($paid->invoice_id)->subtotal_minor)->toBe(1000);
});

it('quotes the full basket without persisting and create freezes the same figures', function () {
    test()->travelTo(CarbonImmutable::parse('2026-06-01T00:00:00Z'));

    $acc = orderAccount();
    $acc->forceFill(['tax_profile' => ['country' => 'DE']])->save();
    $base = orderSetupPrice(1000, 2500);
    $optPrice = Price::create([
        'product_id' => $base->product_id, 'currency' => 'EUR', 'purpose' => 'option', 'amount_minor' => 200,
        'setup_fee_minor' => 300, 'pricing_model' => 'fixed', 'interval' => 'month', 'interval_count' => 1,
    ]);

    $basket = fn () => Meteric::createOrder()
        ->account($acc)
        ->firstPeriod(FirstPeriodPolicy::FullPeriod)
        ->add($base, 1, label: 'srv1')
        ->addon(orderRelativeAddonPrice(20), group: 'backup')
        ->option('ips', '2', 'quantity', $optPrice, 2, label: 'Extra IPs');

    $quote = $basket()->quote();

    expect(Order::count())->toBe(0)
        ->and(Subscription::count())->toBe(0)
        // base 1000 + 20% backup 200 + 2 ips 400 + setups 2500 + 300
        ->and($quote->dueNowSubtotal->getMinorAmount()->toInt())->toBe(4400)
        ->and($quote->setupTotal()->getMinorAmount()->toInt())->toBe(2800)
        ->and($quote->dueNowTax->isPositive())->toBeTrue()
        ->and($quote->recurringTotal->getMinorAmount()->toInt())->toBe(1600)
        ->and($quote->toArray()['due_now']['setup_minor'])->toBe(2800);

    $order = $basket()->create();

    expect($order->subtotal_minor)->toBe($quote->dueNowSubtotal->getMinorAmount()->toInt())
        ->and($order->tax_minor)->toBe($quote->dueNowTax->getMinorAmount()->toInt())
        ->and($order->total_minor)->toBe($quote->dueNowTotal->getMinorAmount()->toInt())
        ->and($order->recurring_total_minor)->toBe($quote->recurringTotal->getMinorAmount()->toInt())
        ->and($order->quote_snapshot)->toEqual($quote->toArray());

    $paid = Meteric::payOrder($order, Money::ofMinor($order->total_minor, 'EUR'));
    $invoice = Invoice::findOrFail($paid->invoice_id);

    expect($invoice->subtotal_minor)->toBe(4400)
        ->and($invoice->total_minor)->toBe($order->total_minor);
});

it('quotes a customer with no billing account yet without creating one', function () {
    test()->travelTo(CarbonImmutable::parse('2026-06-01T00:00:00Z'));
    $customer = new class extends Model
    {
        protected $table = 'users';

        public function getKey()
        {
            return 42;
        }

        public function getMorphClass()
        {
            return 'user';
        }
    };

    $quote = Meteric::createOrder($customer)
        ->tax(new TaxContext(countryCode: 'DE'))
        ->firstPeriod(FirstPeriodPolicy::FullPeriod)
        ->add(orderMonthlyPrice(1000))
        ->quote();

    expect($quote->dueNowSubtotal->getMinorAmount()->toInt())->toBe(1000)
        ->and($quote->dueNowTax->getMinorAmount()->toInt())->toBe(190)
        ->and(BillingAccount::count())->toBe(0);
});

function orderLineBasket(BillingAccount $acc): Order
{
    $base = orderSetupPrice(1000, 2500);
    $optPrice = Price::create([
        'product_id' => $base->product_id, 'currency' => 'EUR', 'purpose' => 'option', 'amount_minor' => 200,
        'setup_fee_minor' => 300, 'pricing_model' => 'fixed', 'interval' => 'month', 'interval_count' => 1,
    ]);

    return Meteric::createOrder()
        ->account($acc)
        ->firstPeriod(FirstPeriodPolicy::FullPeriod)
        ->add($base, 1, label: 'srv1', group: 'l0')
        ->addon(orderRelativeAddonPrice(20), group: 'backup')
        ->option('ips', '2', 'quantity', $optPrice, 2, label: 'Extra IPs')
        ->add(orderYearlyPrice(12000), 1, label: 'example.com', group: 'l1')
        ->create();
}

function orderLineSubscription(BillingAccount $acc): Subscription
{
    return Subscription::create([
        'account_id' => $acc->id, 'customer_type' => 'user', 'customer_id' => '1', 'currency' => 'EUR',
        'state' => SubscriptionState::Active, 'anchor_mode' => 'signup', 'first_period' => 'full_period',
    ]);
}

it('materializes one frozen line onto a host subscription with its frozen charges', function () {
    test()->travelTo(CarbonImmutable::parse('2026-06-01T00:00:00Z'));
    $acc = orderAccount();
    $order = orderLineBasket($acc);
    $sub = orderLineSubscription($acc);
    $resource = new class extends Model
    {
        public function getKey()
        {
            return 7;
        }

        public function getMorphClass()
        {
            return 'service';
        }
    };

    $item = Meteric::materializeLine($order, 'l0', $sub, $resource);

    expect($item->subscription_id)->toBe($sub->id)
        ->and($item->group)->toBe('l0')
        ->and($item->resource_type)->toBe('service')
        ->and($item->resource_id)->toBe('7')
        ->and($sub->fresh()->current_period->end->toIso8601String())->toBe('2026-07-01T00:00:00+00:00')
        ->and($sub->items()->count())->toBe(1)
        ->and(Addon::where('item_id', $item->id)->count())->toBe(1)
        ->and(ItemOption::where('item_id', $item->id)->where('key', 'ips')->exists())->toBeTrue()
        ->and(Order::findOrFail($order->id)->state)->toBe(OrderState::Pending);

    // base 1000, setup 2500, backup 200, ips 400, ips setup 300; the yearly line is untouched.
    $charges = Charge::where('subscription_id', $sub->id)->get();
    expect($charges->sum('amount_minor'))->toBe(4400)
        ->and($charges->where('kind', LineKind::Setup)->pluck('amount_minor')->sort()->values()->all())->toBe([300, 2500])
        ->and($charges->where('kind', LineKind::Addon)->first()->amount_minor)->toBe(200)
        ->and($charges->where('kind', LineKind::Option)->first()->amount_minor)->toBe(400)
        ->and(Charge::count())->toBe(5);

    // Idempotent on the group.
    $again = Meteric::materializeLine($order, 'l0', $sub);
    expect($again->id)->toBe($item->id)
        ->and(Charge::count())->toBe(5);

    // The next cycle renews base, addon and option through the engine, no setup.
    Meteric::renew($sub->fresh(), CarbonImmutable::parse('2026-07-01T00:00:00Z'));
    $july = Charge::whereRaw("lower(covers) = '2026-07-01 00:00:00+00'")->get();
    expect($july->sum('amount_minor'))->toBe(1600)
        ->and(Charge::where('kind', LineKind::Setup->value)->count())->toBe(2);
});

it('materializes lines with the frozen money after a catalog change', function () {
    test()->travelTo(CarbonImmutable::parse('2026-06-01T00:00:00Z'));
    $acc = orderAccount();
    $order = orderLineBasket($acc);
    Price::query()->update(['amount_minor' => 99999]);

    $item = Meteric::materializeLine($order, 'l1', orderLineSubscription($acc));

    expect(Charge::where('origin_id', $item->id)->first()->amount_minor)->toBe(12000)
        ->and($item->current_period->end->toIso8601String())->toBe('2027-06-01T00:00:00+00:00');
});

it('refuses to materialize a line whose base price cannot renew exactly', function () {
    test()->travelTo(CarbonImmutable::parse('2026-06-01T00:00:00Z'));
    $acc = orderAccount();
    $blocks = orderMonthlyPrice(500);
    $blocks->forceFill(['block_size' => 50])->save();

    $order = Meteric::createOrder()->account($acc)
        ->add(orderRelativeAddonPrice(20), 1, group: 'rel')
        ->add($blocks, 120, group: 'blk')
        ->create();
    $sub = orderLineSubscription($acc);

    expect(fn () => Meteric::materializeLine($order, 'rel', $sub))->toThrow(LineNotMaterializable::class, 'relative')
        ->and(fn () => Meteric::materializeLine($order, 'blk', $sub))->toThrow(LineNotMaterializable::class, 'block')
        ->and(fn () => Meteric::materializeLine($order, 'nope', $sub))->toThrow(InvalidArgumentException::class, 'no line')
        ->and($sub->items()->count())->toBe(0)
        ->and(Charge::count())->toBe(0);
});

it('refuses to materialize a line of a canceled order or onto another currency', function () {
    test()->travelTo(CarbonImmutable::parse('2026-06-01T00:00:00Z'));
    $acc = orderAccount();
    $order = orderLineBasket($acc);
    $usd = Subscription::create([
        'account_id' => $acc->id, 'customer_type' => 'user', 'customer_id' => '1', 'currency' => 'USD',
        'state' => SubscriptionState::Active, 'anchor_mode' => 'signup', 'first_period' => 'full_period',
    ]);

    expect(fn () => Meteric::materializeLine($order, 'l0', $usd))->toThrow(InvalidArgumentException::class, 'currency');

    Meteric::cancelOrder($order);
    expect(fn () => Meteric::materializeLine($order->fresh(), 'l0', orderLineSubscription($acc)))->toThrow(LogicException::class);
});
