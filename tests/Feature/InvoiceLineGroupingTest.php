<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Meteric\Enums\ChargeState;
use Meteric\Enums\LineKind;
use Meteric\Enums\OptionType;
use Meteric\Facades\Meteric;
use Meteric\Models\BillingAccount;
use Meteric\Models\Charge;
use Meteric\Models\Price;
use Meteric\Models\Product;
use Meteric\Models\SubscriptionItem;

uses(RefreshDatabase::class);

function lgAccount(): BillingAccount
{
    return BillingAccount::create([
        'owner_type' => 'user', 'owner_id' => '1', 'currency' => 'EUR',
        'tax_profile' => ['country' => 'DE', 'merchant_country' => 'DE'],
    ]);
}

function lgBasePrice(): Price
{
    $p = Product::create(['type' => 'vps', 'slug' => 'lg-'.uniqid(), 'name' => 'VPS XL', 'pricing_model' => 'fixed']);

    return Price::create([
        'product_id' => $p->id, 'currency' => 'EUR', 'amount_minor' => 1000,
        'pricing_model' => 'fixed', 'interval' => 'month', 'interval_count' => 1,
    ]);
}

function lgFixedOption(string $product, int $minor): Price
{
    return Price::create([
        'product_id' => $product, 'currency' => 'EUR', 'purpose' => 'option',
        'pricing_model' => 'fixed', 'amount_minor' => $minor,
        'interval' => 'month', 'interval_count' => 1,
    ]);
}

function lgAddonPrice(int $minor): Price
{
    $p = Product::create(['type' => 'addon', 'slug' => 'lga-'.uniqid(), 'name' => 'Backups', 'pricing_model' => 'fixed']);

    return Price::create([
        'product_id' => $p->id, 'currency' => 'EUR', 'purpose' => 'addon',
        'pricing_model' => 'fixed', 'amount_minor' => $minor,
        'interval' => 'month', 'interval_count' => 1,
    ]);
}

/**
 * A product with a base + a configurable option + an addon, all accrued for the
 * same renewal period. Returns [account, item, the July charges].
 *
 * @return array{BillingAccount, SubscriptionItem, Collection<int,Charge>}
 */
function lgProductWithExtras(): array
{
    $acc = lgAccount();
    $base = lgBasePrice();
    $at = CarbonImmutable::parse('2026-06-01Z');

    $sub = Meteric::subscribe()->account($acc)->at($at)->add($base, 1)->create();
    /** @var SubscriptionItem $item */
    $item = $sub->items()->first();
    $item->setRelation('subscription', $sub)->setRelation('price', $base);

    Meteric::setOption($item, 'slots', '2', OptionType::Quantity->value, lgFixedOption($base->product_id, 300), 2, $at);
    Meteric::addAddon($item, lgAddonPrice(200), group: 'backups', qty: 1, at: $at);

    // Drop the June accrual so only the clean July period remains under test.
    Charge::query()->whereRaw("lower(covers) = '2026-06-01 00:00:00+00'")->delete();
    Charge::query()->whereNull('covers')->delete();

    Meteric::renew($sub->fresh(), CarbonImmutable::parse('2026-07-01Z'));

    $july = Charge::query()
        ->where('account_id', $acc->id)
        ->whereRaw("lower(covers) = '2026-07-01 00:00:00+00'")
        ->orderBy('created_at')
        ->get();

    return [$acc, $item, $july];
}

it('tags base, option, and addon charges with the owning item id as line_group', function () {
    [, $item, $july] = lgProductWithExtras();

    expect($july)->toHaveCount(3)
        ->and($july->pluck('line_group')->unique()->all())->toBe([$item->id])
        ->and($july->pluck('kind')->map->value->sort()->values()->all())
        ->toBe(['addon', 'option', 'recurring']);
});

it('builds a parent product line with its extras as sub-lines, sharing the line_group', function () {
    [$acc, $item, $july] = lgProductWithExtras();

    $invoice = Meteric::invoicePending($acc);

    expect($invoice->lines)->toHaveCount(3)
        ->and($invoice->lines->pluck('line_group')->unique()->all())->toBe([$item->id])
        ->and($invoice->subtotal_minor)->toBe(1800);   // 1000 base + 600 option (300x2) + 200 addon

    // The base charge is the parent; option + addon nest under it as sub-lines.
    $parent = $invoice->lines->whereNull('parent_id')->first();
    $children = $invoice->lines->whereNotNull('parent_id')->values();

    expect($parent->kind)->toBe(LineKind::Recurring)
        ->and($parent->amount_minor)->toBe(1000)               // parent carries its own amount only
        ->and($children)->toHaveCount(2)
        ->and($children->pluck('parent_id')->unique()->all())->toBe([$parent->id])
        ->and($children->pluck('kind')->map->value->sort()->values()->all())->toBe(['addon', 'option'])
        ->and((int) $children->sum('amount_minor'))->toBe(800);   // option 600 + addon 200

    expect(Charge::whereIn('id', $july->pluck('id'))->pluck('state')->unique()->all())->toBe([ChargeState::Invoiced]);
});

it('sums every line, parent and sub-line, into the invoice total', function () {
    [$acc, $item, $july] = lgProductWithExtras();

    $expectedSubtotal = (int) $july->sum('amount_minor');

    $invoice = Meteric::invoicePending($acc);

    // Subtotal sums the parent and all sub-lines; subtotal + tax = total holds.
    expect($invoice->subtotal_minor)->toBe($expectedSubtotal)
        ->and((int) $invoice->lines->sum('amount_minor'))->toBe($expectedSubtotal)
        ->and($invoice->total_minor)->toBe($invoice->subtotal_minor + $invoice->tax_minor);

    // Every charge in the group flips to invoiced.
    expect(Charge::whereIn('id', $july->pluck('id'))->where('state', ChargeState::Invoiced->value)->count())->toBe(3);
});

it('keeps per-line tax so the summed line tax equals the invoice tax', function () {
    [$acc, , $july] = lgProductWithExtras();

    $invoice = Meteric::invoicePending($acc);

    // Each line carries its own tax; summing them must equal the invoice tax
    // (re-derived rather than assumed equal to a single summed-net computation).
    expect((int) $invoice->lines->sum('tax_minor'))->toBe($invoice->tax_minor)
        ->and($invoice->total_minor)->toBe($invoice->subtotal_minor + $invoice->tax_minor);
});

it('keeps an account-level charge with no item as its own standalone line', function () {
    $acc = lgAccount();
    Charge::create([
        'account_id' => $acc->id,
        'origin_type' => 'manual', 'origin_id' => (string) Str::uuid(),
        'kind' => LineKind::OneOff, 'billing_mode' => 'in_advance',
        'state' => ChargeState::Pending, 'description' => 'Domain registration',
        'quantity' => 1, 'unit_minor' => 900, 'amount_minor' => 900,
        'currency' => 'EUR', 'idempotency_key' => (string) Str::uuid(),
    ]);

    $invoice = Meteric::invoicePending($acc);

    expect($invoice->lines)->toHaveCount(1)
        ->and($invoice->lines->first()->line_group)->toBeNull()
        ->and($invoice->lines->first()->amount_minor)->toBe(900);
});
