<?php

declare(strict_types=1);

use Brick\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Meteric\Enums\CommitmentState;
use Meteric\Enums\Interval;
use Meteric\Facades\Meteric;
use Meteric\Models\BillingAccount;
use Meteric\Models\Charge;
use Meteric\Models\Price;
use Meteric\Models\Product;
use Meteric\Models\SubscriptionItem;

uses(RefreshDatabase::class);

function commitItem(int $minor = 1000): SubscriptionItem
{
    $acc = BillingAccount::create(['owner_type' => 'user', 'owner_id' => '1', 'currency' => 'EUR']);
    $product = Product::create(['type' => 'vps', 'slug' => 'c-'.uniqid(), 'name' => 'VPS', 'pricing_model' => 'fixed']);
    $price = Price::create([
        'product_id' => $product->id, 'currency' => 'EUR', 'amount_minor' => $minor,
        'pricing_model' => 'fixed', 'interval' => 'month', 'interval_count' => 1,
    ]);
    $sub = Meteric::subscribe()->account($acc)->at(CarbonImmutable::parse('2026-06-01T00:00:00Z'))->add($price, 1)->create();
    $item = $sub->items->first();
    $item->setRelation('subscription', $sub);
    $item->setRelation('price', $price);

    return $item;
}

it('bills the upfront and records the commitment', function () {
    $item = commitItem();

    $commitment = Meteric::commit($item, Interval::Year, 1, Money::of('120.00', 'EUR'), Money::of('8.00', 'EUR'), at: CarbonImmutable::parse('2026-06-01T00:00:00Z'));

    expect($commitment->state)->toBe(CommitmentState::Active);
    $upfront = Charge::where('origin_type', 'commitment')->where('description', 'Commitment upfront')->first();
    expect($upfront->amount_minor)->toBe(12000);
});

it('applies the committed rate on renewal', function () {
    $item = commitItem(1000); // €10/mo list price

    Meteric::commit($item, Interval::Year, 1, Money::of('0', 'EUR'), Money::of('8.00', 'EUR'), at: CarbonImmutable::parse('2026-06-01T00:00:00Z'));

    // Renew into July — committed €8 applies, not the €10 list.
    $sub = $item->subscription;
    Meteric::renew($sub, CarbonImmutable::parse('2026-07-02T00:00:00Z'));

    // June billed at list (1000, pre-commitment); July billed at committed 800.
    expect(Charge::where('subscription_id', $sub->id)->count())->toBe(2)
        ->and(Charge::where('subscription_id', $sub->id)->where('amount_minor', 800)->exists())->toBeTrue();
});

it('charges a fixed early termination fee', function () {
    $item = commitItem();
    $commitment = Meteric::commit($item, Interval::Year, 1, Money::of('0', 'EUR'), Money::of('8.00', 'EUR'),
        earlyTerm: ['fee_minor' => 5000], at: CarbonImmutable::parse('2026-06-01T00:00:00Z'));

    $fee = Meteric::terminateCommitment($commitment, CarbonImmutable::parse('2026-08-01T00:00:00Z'));

    expect($fee->getMinorAmount()->toInt())->toBe(5000)
        ->and($commitment->fresh()->state)->toBe(CommitmentState::Terminated)
        ->and(Charge::where('description', 'Early termination fee')->exists())->toBeTrue();
});
