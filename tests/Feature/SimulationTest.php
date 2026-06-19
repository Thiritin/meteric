<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Meteric\Enums\DowngradePolicy;
use Meteric\Facades\Meteric;
use Meteric\Models\BillingAccount;
use Meteric\Models\Charge;
use Meteric\Models\MeterDimension;
use Meteric\Models\Price;
use Meteric\Models\Product;
use Meteric\Support\Period;

uses(RefreshDatabase::class);

function monthlyPlan(string $slug, int $minor): Price
{
    $p = Product::create(['type' => 'vps', 'slug' => $slug.'-'.uniqid(), 'name' => $slug, 'pricing_model' => 'fixed']);

    return Price::create([
        'product_id' => $p->id, 'currency' => 'EUR', 'amount_minor' => $minor,
        'pricing_model' => 'fixed', 'interval' => 'month', 'interval_count' => 1,
    ]);
}

function hourlyPlan(string $slug, string $rate): Price
{
    $p = Product::create(['type' => 'vps', 'slug' => $slug.'-'.uniqid(), 'name' => $slug, 'pricing_model' => 'hourly']);
    MeterDimension::create([
        'product_id' => $p->id, 'key' => 'hours', 'unit' => 'h', 'aggregation' => 'sum',
        'rate' => $rate, 'currency' => 'EUR', 'included_qty' => 0,
    ]);

    return Price::create([
        'product_id' => $p->id, 'currency' => 'EUR', 'amount_minor' => 0,
        'pricing_model' => 'hourly', 'interval' => 'month', 'interval_count' => 1, 'billing_mode' => 'in_arrears',
    ]);
}

// ── Monthly: buy VPS S → upgrade to M → downgrade back to S (prepaid, discard) ──
it('simulates a full monthly cycle S → M → S with prepaid discard downgrade', function () {
    $acc = BillingAccount::create(['owner_type' => 'user', 'owner_id' => '1', 'currency' => 'EUR']);
    $s = monthlyPlan('vps-s', 1000); // €10/mo
    $m = monthlyPlan('vps-m', 2000); // €20/mo

    // Day 1 — buy S, billed €10 for June.
    $sub = Meteric::subscribe()->account($acc)->at(CarbonImmutable::parse('2026-06-01T00:00:00Z'))->add($s, 1)->create();
    $item = $sub->items->first();
    $item->setRelation('subscription', $sub)->setRelation('price', $s);
    expect((int) Charge::where('subscription_id', $sub->id)->sum('amount_minor'))->toBe(1000);

    // Day 11 — upgrade to M: credit unused S + charge prorated M (20/30 of the month left).
    Meteric::changePlan($item, $m, at: CarbonImmutable::parse('2026-06-11T00:00:00Z'));
    $item = $item->fresh()->setRelation('subscription', $sub);
    expect($item->price_id)->toBe($m->id);
    // 1000 (S) - 667 (unused S, 20/30) + 1333 (prorated M, 20/30) — proration second-precise
    expect(Charge::where('subscription_id', $sub->id)->count())->toBe(3);

    // Day 21 — downgrade back to S, prepaid discard: switch now, no money.
    Meteric::changePlan($item, $s, DowngradePolicy::Discard, CarbonImmutable::parse('2026-06-21T00:00:00Z'));
    $item = $item->fresh()->setRelation('subscription', $sub);
    expect($item->price_id)->toBe($s->id)
        ->and(Charge::where('subscription_id', $sub->id)->count())->toBe(3); // no new charge

    // Day 30 — renewal bills S for July at €10.
    Meteric::renew($sub, CarbonImmutable::parse('2026-07-01T00:00:00Z'));
    expect($item->fresh()->price_id)->toBe($s->id)
        ->and(Charge::where('subscription_id', $sub->id)->where('amount_minor', 1000)->count())->toBe(2); // June S + July S

    // Net billed: 1000 - 667 + 1333 + 1000 = 2666 (upgrade window, then back to S).
    expect((int) Charge::where('subscription_id', $sub->id)->sum('amount_minor'))->toBe(2666);
});

// ── Hourly: same S → M → S, billed purely on runtime hours, in arrears ──
it('simulates a full hourly cycle S → M → S billed on usage', function () {
    $acc = BillingAccount::create(['owner_type' => 'user', 'owner_id' => '1', 'currency' => 'EUR']);
    $s = hourlyPlan('hvps-s', '0.010000'); // €0.01/h
    $m = hourlyPlan('hvps-m', '0.020000'); // €0.02/h

    $sub = Meteric::subscribe()->account($acc)->at(CarbonImmutable::parse('2026-06-01T00:00:00Z'))->add($s, 1)->create();
    $item = $sub->items->first();
    $item->setRelation('subscription', $sub)->setRelation('price', $s);

    // Runs as S for 100h, upgrade to M, runs 200h, downgrade to S, runs 100h.
    Meteric::recordUsage($item, 'hours', 100, CarbonImmutable::parse('2026-06-05T00:00:00Z'));

    Meteric::changePlan($item, $m, DowngradePolicy::Discard, CarbonImmutable::parse('2026-06-10T00:00:00Z'));
    $item = $item->fresh()->setRelation('subscription', $sub);
    Meteric::recordUsage($item, 'hours', 200, CarbonImmutable::parse('2026-06-15T00:00:00Z'));

    Meteric::changePlan($item, $s, DowngradePolicy::Discard, CarbonImmutable::parse('2026-06-20T00:00:00Z'));
    $item = $item->fresh()->setRelation('subscription', $sub);
    Meteric::recordUsage($item, 'hours', 100, CarbonImmutable::parse('2026-06-25T00:00:00Z'));

    // Close the month — bill each tier's hours at its own rate.
    $charges = Meteric::rollupUsage($item, new Period(CarbonImmutable::parse('2026-06-01Z'), CarbonImmutable::parse('2026-07-01Z')));

    // S: 200h × €0.01 = €2.00 ; M: 200h × €0.02 = €4.00 ; total €6.00
    expect(collect($charges)->sum('amount_minor'))->toBe(600)
        ->and($charges)->toHaveCount(2);

    $invoice = Meteric::invoicePending($acc);
    expect($invoice->subtotal_minor)->toBe(600);
});
