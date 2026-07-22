<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Meteric\Enums\ChargeState;
use Meteric\Enums\DowngradePolicy;
use Meteric\Enums\InvoiceState;
use Meteric\Enums\ItemState;
use Meteric\Enums\LineKind;
use Meteric\Facades\Meteric;
use Meteric\Models\BillingAccount;
use Meteric\Models\Charge;
use Meteric\Models\MeterDimension;
use Meteric\Models\Price;
use Meteric\Models\Product;
use Meteric\Support\Period;

uses(RefreshDatabase::class);

function chainPrice(int $minor, string $slug, string $model = 'fixed'): Price
{
    $product = Product::create(['type' => 'vps', 'slug' => $slug.'-'.uniqid(), 'name' => $slug, 'pricing_model' => $model]);

    return Price::create([
        'product_id' => $product->id, 'currency' => 'EUR', 'amount_minor' => $minor,
        'pricing_model' => $model, 'interval' => 'month', 'interval_count' => 1,
    ]);
}

function chainMeteredPrice(string $rate): Price
{
    $product = Product::create(['type' => 'cloud', 'slug' => 'chain-meter-'.uniqid(), 'name' => 'Traffic', 'pricing_model' => 'metered']);
    MeterDimension::create([
        'product_id' => $product->id, 'key' => 'traffic', 'unit' => 'GB',
        'aggregation' => 'sum', 'rate' => $rate, 'currency' => 'EUR', 'included_qty' => 0,
    ]);

    return Price::create([
        'product_id' => $product->id, 'currency' => 'EUR', 'amount_minor' => 0,
        'pricing_model' => 'metered', 'interval' => 'month', 'interval_count' => 1, 'billing_mode' => 'in_arrears',
    ]);
}

it('runs a full subscription lifecycle and reconciles the invoice', function () {
    $acc = BillingAccount::create([
        'owner_type' => 'user', 'owner_id' => '1', 'currency' => 'EUR',
        'tax_profile' => ['country' => 'DE', 'merchant_country' => 'DE'],
    ]);

    $base = chainPrice(3000, 'base');          // €30/mo fixed base plan
    $metered = chainMeteredPrice('0.500000');  // €0.50/GB metered item

    $start = CarbonImmutable::parse('2026-06-01T00:00:00Z');

    // 1. Subscribe to the base plan plus a metered item.
    $sub = Meteric::subscribe()->account($acc)->at($start)
        ->add($base, 1)
        ->add($metered, 1)
        ->create();

    $baseItem = $sub->items->firstWhere('product_id', $base->product_id);
    $baseItem->setRelation('subscription', $sub);
    $baseItem->setRelation('price', $base);
    $meterItem = $sub->items->firstWhere('product_id', $metered->product_id);
    $meterItem->setRelation('subscription', $sub);

    // First cycle: the fixed base bills its full €30 now.
    expect(Charge::where('subscription_id', $sub->id)->where('amount_minor', 3000)->where('kind', LineKind::Recurring->value)->exists())->toBeTrue();

    $mid = CarbonImmutable::parse('2026-06-16T00:00:00Z'); // 15/30 days remaining

    // 2. Add an addon (prorated).
    Meteric::addAddon($baseItem, chainPrice(1000, 'ram'), group: 'ram', qty: 1, at: $mid);

    // 3. Set a configurable option (prorated).
    Meteric::setOption($baseItem, 'slots', '4', 'quantity', price: chainPrice(50, 'slot', 'per_unit'), qty: 4, at: $mid);

    // 4. Record metered usage on the metered item.
    Meteric::recordUsage($meterItem, 'traffic', 60, CarbonImmutable::parse('2026-06-10Z'));
    Meteric::recordUsage($meterItem, 'traffic', 40, CarbonImmutable::parse('2026-06-20Z'));

    // 5. Upgrade then downgrade (downgrade deferred, no money mid-cycle).
    $bigger = chainPrice(5000, 'bigger');
    Meteric::changePlan($baseItem->fresh()->setRelation('subscription', $sub), $bigger, at: $mid);
    $smaller = chainPrice(4000, 'smaller');
    Meteric::changePlan($baseItem->fresh()->setRelation('subscription', $sub), $smaller, DowngradePolicy::Defer, at: $mid);

    // 6. Roll usage up into an in-arrears charge.
    $window = new Period(CarbonImmutable::parse('2026-06-01Z'), CarbonImmutable::parse('2026-07-01Z'));
    $usageCharges = Meteric::rollupUsage($meterItem, $window);
    expect($usageCharges)->toHaveCount(1)
        ->and($usageCharges[0]->amount_minor)->toBe(5000); // 100 GB × €0.50

    // 7. Renew into the next cycle (the deferred downgrade applies, base bills €40).
    Meteric::renew($sub->fresh(), CarbonImmutable::parse('2026-07-02T00:00:00Z'));

    // 8. Invoice everything pending and assert coherence.
    $pendingBefore = Charge::where('subscription_id', $sub->id)->pending()->get();
    $expectedSubtotal = (int) $pendingBefore->sum('amount_minor');

    $invoice = Meteric::invoicePending($acc);

    expect($invoice)->not->toBeNull()
        ->and($invoice->state)->toBe(InvoiceState::Open)
        ->and($invoice->subtotal_minor)->toBe($expectedSubtotal)
        ->and($invoice->total_minor)->toBe($invoice->subtotal_minor + $invoice->tax_minor)
        ->and($invoice->lines)->toHaveCount($pendingBefore->count());

    // The line set covers the expected variety of charge kinds.
    $kinds = $invoice->lines->pluck('kind')->map(fn ($k) => $k->value ?? $k)->unique()->values()->all();
    expect($kinds)->toContain(LineKind::Recurring->value)  // first base cycle + renewal
        ->and($kinds)->toContain(LineKind::Addon->value)
        ->and($kinds)->toContain(LineKind::Option->value)
        ->and($kinds)->toContain(LineKind::Usage->value);

    // The deferred downgrade applied at the boundary: the renewal bills €40, not the €30 it started on.
    expect(Charge::where('subscription_id', $sub->id)->where('amount_minor', 4000)->where('kind', LineKind::Recurring->value)->exists())->toBeTrue();

    // Invoice lines reconcile to the sum of their own amounts.
    expect((int) $invoice->lines->sum('amount_minor'))->toBe($invoice->subtotal_minor);

    // Everything that was pending is now invoiced; nothing left pending.
    expect(Charge::where('subscription_id', $sub->id)->pending()->count())->toBe(0)
        ->and(Charge::where('subscription_id', $sub->id)->where('state', ChargeState::Invoiced->value)->count())->toBe($pendingBefore->count());

    // The deferred downgrade was applied at the renewal boundary: now on the smaller plan.
    expect($baseItem->fresh()->state)->toBe(ItemState::Active)
        ->and($baseItem->fresh()->price_id)->toBe($smaller->id)
        ->and($baseItem->fresh()->pending_change)->toBeNull();
});
