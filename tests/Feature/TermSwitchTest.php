<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Meteric\Enums\FirstPeriodPolicy;
use Meteric\Enums\LineKind;
use Meteric\Exceptions\TermNotSwitchable;
use Meteric\Facades\Meteric;
use Meteric\Models\BillingAccount;
use Meteric\Models\BillingPeriod;
use Meteric\Models\Charge;
use Meteric\Models\MeterDimension;
use Meteric\Models\Price;
use Meteric\Models\Product;
use Meteric\Models\Subscription;
use Meteric\Pricing\DiscountSpec;

uses(RefreshDatabase::class);

/** One product carrying both terms, so a switch stays on the same product. */
function switchProduct(): Product
{
    return Product::create(['type' => 'vps', 'slug' => 'ts-'.uniqid(), 'name' => 'VPS', 'pricing_model' => 'fixed']);
}

function switchPrice(Product $product, int $minor, string $interval): Price
{
    return Price::create([
        'product_id' => $product->id, 'currency' => 'EUR', 'amount_minor' => $minor,
        'pricing_model' => 'fixed', 'interval' => $interval, 'interval_count' => 1,
    ]);
}

/** A monthly subscription billed whole for June 2026 (30 days, so one day is 1.00). */
function switchSub(Price $monthly): Subscription
{
    $acc = BillingAccount::create(['owner_type' => 'user', 'owner_id' => '1', 'currency' => 'EUR']);

    return Meteric::subscribe()->account($acc)
        ->firstPeriod(FirstPeriodPolicy::FullPeriod)
        ->at(CarbonImmutable::parse('2026-06-01T00:00:00Z'))
        ->add($monthly)
        ->create();
}

it('closes the running period and opens the new term from the switch date', function () {
    $product = switchProduct();
    $monthly = switchPrice($product, 3000, 'month');
    $yearly = switchPrice($product, 30000, 'year');
    $item = switchSub($monthly)->items()->first();

    $item = Meteric::switchTerm($item, $yearly, CarbonImmutable::parse('2026-06-16T00:00:00Z'));

    expect($item->price_id)->toBe($yearly->id)
        ->and($item->current_period->start->toIso8601String())->toBe('2026-06-16T00:00:00+00:00')
        ->and($item->current_period->end->toIso8601String())->toBe('2027-06-16T00:00:00+00:00')
        ->and($item->subscription->fresh()->current_period->end->toIso8601String())->toBe('2027-06-16T00:00:00+00:00');

    // 15 of June's 30 days unused at 30.00, then the year billed whole.
    $credit = Charge::where('kind', LineKind::Credit->value)->firstOrFail();
    $opened = Charge::where('kind', LineKind::Recurring->value)->where('amount_minor', 30000)->firstOrFail();

    expect($credit->amount_minor)->toBe(-1500)
        ->and($credit->description)->toBe('Unused VPS')
        ->and($opened->covers->start->toIso8601String())->toBe('2026-06-16T00:00:00+00:00')
        ->and((int) Charge::sum('amount_minor'))->toBe(3000 - 1500 + 30000);
});

it('shortens the billed window so the period opening inside it still bills', function () {
    $product = switchProduct();
    $item = switchSub($monthly = switchPrice($product, 3000, 'month'))->items()->first();
    $yearly = switchPrice($product, 30000, 'year');

    Meteric::switchTerm($item, $yearly, CarbonImmutable::parse('2026-06-16T00:00:00Z'));

    $windows = BillingPeriod::where('item_id', $item->id)->whereNull('dimension_id')
        ->get()->map(fn (BillingPeriod $w) => $w->covers->start->toDateString().'/'.$w->covers->end->toDateString())
        ->sort()->values()->all();

    expect($windows)->toBe(['2026-06-01/2026-06-16', '2026-06-16/2027-06-16'])
        ->and($monthly->fresh()->id)->toBe($monthly->id);
});

it('rolls up the closing window usage instead of carrying it into the new period', function () {
    $product = switchProduct();
    MeterDimension::create([
        'product_id' => $product->id, 'key' => 'traffic', 'unit' => 'GB',
        'aggregation' => 'sum', 'rate' => '0.10000000', 'currency' => 'EUR', 'included_qty' => 0,
    ]);
    $monthly = switchPrice($product, 3000, 'month');
    $yearly = switchPrice($product, 30000, 'year');
    $item = switchSub($monthly)->items()->first();

    Meteric::recordUsage($item, 'traffic', 100, CarbonImmutable::parse('2026-06-05T00:00:00Z'), 'u1');
    Meteric::recordUsage($item, 'traffic', 50, CarbonImmutable::parse('2026-06-20T00:00:00Z'), 'u2');

    $at = CarbonImmutable::parse('2026-06-16T00:00:00Z');
    $preview = Meteric::previewTermSwitch($item, $yearly, $at);
    Meteric::switchTerm($item, $yearly, $at);

    $usage = Charge::where('kind', LineKind::Usage->value)->firstOrFail();

    // The 100 GB before the switch is rated and billed with the period it
    // belongs to; the 50 GB after it stays unbilled for the new period.
    expect($usage->amount_minor)->toBe(1000)
        ->and($usage->covers->end->toIso8601String())->toBe('2026-06-16T00:00:00+00:00')
        ->and($preview->usage->getMinorAmount()->toInt())->toBe(1000)
        ->and($preview->usageLines[0]['dimension'])->toBe('traffic')
        ->and(Charge::where('kind', LineKind::Usage->value)->count())->toBe(1);
});

it('previews exactly what the switch charges', function () {
    $product = switchProduct();
    $monthly = switchPrice($product, 3000, 'month');
    $yearly = switchPrice($product, 30000, 'year');
    $item = switchSub($monthly)->items()->first();
    $at = CarbonImmutable::parse('2026-06-16T00:00:00Z');

    $preview = Meteric::previewTermSwitch($item, $yearly, $at);
    Meteric::switchTerm($item, $yearly, $at);

    $moved = Charge::whereIn('kind', [LineKind::Credit->value, LineKind::Recurring->value])
        ->where('created_at', '>=', now()->subMinute())->sum('amount_minor');

    expect($preview->unused->getMinorAmount()->toInt())->toBe(1500)
        ->and($preview->recurring->getMinorAmount()->toInt())->toBe(30000)
        ->and($preview->total()->getMinorAmount()->toInt())->toBe(28500)
        // The first period's own 30.00 was billed before the switch.
        ->and((int) $moved - 3000)->toBe(28500);
});

it('credits the addons and options the closing window billed, because the new period bills them again', function () {
    $product = switchProduct();
    $monthly = switchPrice($product, 3000, 'month');
    $yearly = switchPrice($product, 30000, 'year');

    $extra = Product::create(['type' => 'addon', 'slug' => 'ts-bkp-'.uniqid(), 'name' => 'Backup', 'pricing_model' => 'fixed']);
    $extraPrice = switchPrice($extra, 1000, 'month');

    $item = switchSub($monthly)->items()->first();
    Meteric::addAddon($item, $extraPrice, at: CarbonImmutable::parse('2026-06-01T00:00:00Z'));

    // June billed 30.00 + 10.00; half of it is unused at the switch.
    $preview = Meteric::previewTermSwitch($item, $yearly, CarbonImmutable::parse('2026-06-16T00:00:00Z'));

    expect($preview->unused->getMinorAmount()->toInt())->toBe(2000);
});

it('quotes the new period with its discount, and the switch bills the same', function () {
    $product = switchProduct();
    $monthly = switchPrice($product, 3000, 'month');
    $yearly = switchPrice($product, 30000, 'year');
    $item = switchSub($monthly)->items()->first();

    Meteric::applyDiscount($item, DiscountSpec::percent('10', 'Loyalty'));

    $at = CarbonImmutable::parse('2026-06-16T00:00:00Z');
    $preview = Meteric::previewTermSwitch($item, $yearly, $at);
    Meteric::switchTerm($item, $yearly, $at);

    $billed = Charge::whereIn('kind', [LineKind::Recurring->value, LineKind::Discount->value])
        ->whereRaw('lower(covers) = ?', [$at])->sum('amount_minor');

    expect($preview->recurring->getMinorAmount()->toInt())->toBe(27000)
        ->and((int) $billed)->toBe(27000);
});

it('refuses a switch outside the running period', function () {
    $product = switchProduct();
    $item = switchSub(switchPrice($product, 3000, 'month'))->items()->first();
    $yearly = switchPrice($product, 30000, 'year');

    Meteric::switchTerm($item, $yearly, CarbonImmutable::parse('2026-07-02T00:00:00Z'));
})->throws(TermNotSwitchable::class);

it('renews on the new term after the switch', function () {
    $product = switchProduct();
    $item = switchSub(switchPrice($product, 3000, 'month'))->items()->first();
    $yearly = switchPrice($product, 30000, 'year');

    $item = Meteric::switchTerm($item, $yearly, CarbonImmutable::parse('2026-06-16T00:00:00Z'));

    expect(Meteric::renew($item->subscription, CarbonImmutable::parse('2026-08-01T00:00:00Z')))->toBe([]);

    Meteric::renew($item->subscription, CarbonImmutable::parse('2027-06-17T00:00:00Z'));

    expect($item->fresh()->current_period->end->toIso8601String())->toBe('2028-06-16T00:00:00+00:00')
        ->and(Charge::where('amount_minor', 30000)->count())->toBe(2);
});
