<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Meteric\Enums\DowngradePolicy;
use Meteric\Enums\LineKind;
use Meteric\Enums\OptionType;
use Meteric\Enums\UpgradePolicy;
use Meteric\Facades\Meteric;
use Meteric\Models\BillingAccount;
use Meteric\Models\Charge;
use Meteric\Models\Price;
use Meteric\Models\Product;
use Meteric\Models\Subscription;
use Meteric\Models\SubscriptionItem;

uses(RefreshDatabase::class);

function pcmAccount(): BillingAccount
{
    return BillingAccount::create(['owner_type' => 'user', 'owner_id' => '1', 'currency' => 'EUR']);
}

function pcmPlan(int $minor, string $mode = 'in_advance'): Price
{
    $p = Product::create(['type' => 'vps', 'slug' => 'pcm-'.uniqid(), 'name' => 'VPS '.$minor, 'pricing_model' => 'fixed']);

    return Price::create([
        'product_id' => $p->id, 'currency' => 'EUR', 'amount_minor' => $minor,
        'pricing_model' => 'fixed', 'interval' => 'month', 'interval_count' => 1, 'billing_mode' => $mode,
    ]);
}

function pcmExtra(string $product, string $purpose, int $minor): Price
{
    return Price::create([
        'product_id' => $product, 'currency' => 'EUR', 'purpose' => $purpose,
        'pricing_model' => 'fixed', 'amount_minor' => $minor, 'interval' => 'month', 'interval_count' => 1,
    ]);
}

/** Subscribe one labelled, grouped base item. Returns the live item with relations set. */
function pcmItem(BillingAccount $acc, Price $base, string $label = 'web1.example', string $group = 'Servers'): SubscriptionItem
{
    $sub = Meteric::subscribe()->account($acc)->at(CarbonImmutable::parse('2026-06-01Z'))
        ->add($base, 1, null, label: $label, group: $group)->create();
    $item = $sub->items->first();
    $item->setRelation('subscription', $sub)->setRelation('price', $base);

    return $item;
}

function pcmJuly(Subscription $sub): Collection
{
    return Charge::where('subscription_id', $sub->id)->whereRaw("lower(covers) = '2026-07-01 00:00:00+00'")->get();
}

it('a change on an in-arrears item is rate-forward with no proration', function () {
    $acc = pcmAccount();
    $a = pcmPlan(1000, 'in_arrears');
    $b = pcmPlan(2000, 'in_arrears');
    $item = pcmItem($acc, $a);
    $before = Charge::where('subscription_id', $item->subscription_id)->count();

    Meteric::changePlan($item, $b, at: CarbonImmutable::parse('2026-06-16Z'));

    // Swapped, but no credit / prorated / refund charge: postpaid has no prepaid value.
    expect($item->fresh()->price_id)->toBe($b->id)
        ->and(Charge::where('subscription_id', $item->subscription_id)->count())->toBe($before);
});

it('upgrading the base keeps options and addons recurring next cycle', function () {
    $acc = pcmAccount();
    $small = pcmPlan(1000);
    $large = pcmPlan(3000);
    $item = pcmItem($acc, $small);
    $sub = $item->subscription;

    Meteric::setOption($item, 'slots', '4', OptionType::Quantity->value, pcmExtra($small->product_id, 'option', 200), 4, CarbonImmutable::parse('2026-06-02Z'));
    Meteric::addAddon($item->fresh()->setRelation('subscription', $sub), pcmExtra($small->product_id, 'addon', 300), null, 1, CarbonImmutable::parse('2026-06-03Z'));

    Meteric::changePlan($item->fresh()->setRelation('subscription', $sub)->setRelation('price', $small), $large, upgrade: UpgradePolicy::Prorate, at: CarbonImmutable::parse('2026-06-16Z'));
    Meteric::renew($sub->fresh(), CarbonImmutable::parse('2026-07-02Z'));

    $july = pcmJuly($sub);
    expect($july->where('kind', LineKind::Recurring->value)->where('amount_minor', 3000)->count())->toBe(1)  // upgraded base
        ->and($july->where('kind', LineKind::Option->value)->where('amount_minor', 800)->count())->toBe(1)    // 4 slots x 2.00
        ->and($july->where('kind', LineKind::Addon->value)->where('amount_minor', 300)->count())->toBe(1);
});

it('credit downgrade credits the base and leaves the option recurring', function () {
    $acc = pcmAccount();
    $large = pcmPlan(3000);
    $small = pcmPlan(1000);
    $item = pcmItem($acc, $large);
    $sub = $item->subscription;

    Meteric::setOption($item, 'slots', '2', OptionType::Quantity->value, pcmExtra($large->product_id, 'option', 200), 2, CarbonImmutable::parse('2026-06-02Z'));
    Meteric::changePlan($item->fresh()->setRelation('subscription', $sub)->setRelation('price', $large), $small, DowngradePolicy::Credit, at: CarbonImmutable::parse('2026-06-16Z'));

    $credits = Charge::where('subscription_id', $sub->id)->where('kind', LineKind::Credit->value)->get();
    expect($item->fresh()->price_id)->toBe($small->id)
        ->and($credits)->toHaveCount(1)
        ->and($credits->first()->amount_minor)->toBeLessThan(0)        // unused large, negative
        ->and($item->options()->where('key', 'slots')->exists())->toBeTrue();

    // Next cycle still bills the option.
    Meteric::renew($sub->fresh(), CarbonImmutable::parse('2026-07-02Z'));
    expect(pcmJuly($sub)->where('kind', LineKind::Option->value)->count())->toBe(1);
});

it('produces an itemized, readable invoice across base, option and addon', function () {
    $acc = pcmAccount();
    $base = pcmPlan(1000);
    $item = pcmItem($acc, $base, label: 'web1.example', group: 'Servers');
    $sub = $item->subscription;

    Meteric::setOption($item, 'slots', '4', OptionType::Quantity->value, pcmExtra($base->product_id, 'option', 200), 4, CarbonImmutable::parse('2026-06-02Z'));
    Meteric::addAddon($item->fresh()->setRelation('subscription', $sub), pcmExtra($base->product_id, 'addon', 300), null, 1, CarbonImmutable::parse('2026-06-03Z'));

    $invoice = Meteric::invoicePending($acc);
    $lines = $invoice->lines;

    // One line per thing, each itemized with a title (no single lumped line).
    expect($lines->count())->toBe(3)
        ->and($lines->whereNull('title')->count())->toBe(0)
        ->and($lines->firstWhere('kind', LineKind::Recurring)->title)->toBe('VPS 1000 - web1.example')
        ->and($lines->firstWhere('kind', LineKind::Recurring)->group)->toBe('Servers')
        ->and($lines->firstWhere('kind', LineKind::Recurring)->unit)->toBe('month')
        ->and($lines->firstWhere('kind', LineKind::Option)->description)->toBe('Slots')
        ->and($lines->firstWhere('kind', LineKind::Addon))->not->toBeNull();
});
