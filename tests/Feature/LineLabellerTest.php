<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Meteric\Contracts\LineLabeller;
use Meteric\Enums\ChargeReason;
use Meteric\Facades\Meteric;
use Meteric\Invoicing\LineContext;
use Meteric\Invoicing\LineLabel;
use Meteric\Models\BillingAccount;
use Meteric\Models\Price;
use Meteric\Models\Product;

uses(RefreshDatabase::class);

final class EventLabeller implements LineLabeller
{
    public function label(LineContext $context): ?LineLabel
    {
        if ($context->item->product->type !== 'domain') {
            return null;
        }

        $event = match ($context->reason) {
            ChargeReason::Initial => 'Create',
            ChargeReason::Renewal => 'Renew',
            default => null,
        };

        return new LineLabel($context->item->label.($event === null ? '' : ' - '.$event));
    }
}

function labellerAccount(): BillingAccount
{
    return BillingAccount::create([
        'owner_type' => 'user', 'owner_id' => '1', 'currency' => 'EUR',
        'tax_profile' => ['country' => 'US', 'merchant_country' => 'DE'],
    ]);
}

function labellerPrice(string $type, string $name): Price
{
    $product = Product::create(['type' => $type, 'slug' => 'll-'.uniqid(), 'name' => $name, 'pricing_model' => 'fixed']);

    return Price::create([
        'product_id' => $product->id, 'currency' => 'EUR', 'amount_minor' => 1000,
        'pricing_model' => 'fixed', 'interval' => 'month', 'interval_count' => 1,
    ]);
}

beforeEach(function () {
    config()->set('meteric.line_labeller', EventLabeller::class);
});

it('lets a labeller word the line for the event rather than the product', function () {
    $acc = labellerAccount();

    Meteric::subscribe()->account($acc)
        ->at(CarbonImmutable::parse('2026-06-01Z'))
        ->add(labellerPrice('domain', 'Domain .de'), 1, null, label: 'thiritin.com')
        ->create();

    $line = Meteric::invoicePending($acc)->lines->first();

    expect($line->title)->toBe('thiritin.com - Create')
        ->and($line->description)->toBeNull();
});

it('words the next period as a renewal', function () {
    $acc = labellerAccount();

    $sub = Meteric::subscribe()->account($acc)
        ->at(CarbonImmutable::parse('2026-06-01Z'))
        ->add(labellerPrice('domain', 'Domain .de'), 1, null, label: 'thiritin.com')
        ->create();

    Meteric::renew($sub, CarbonImmutable::parse('2026-07-02Z'));

    $titles = Meteric::invoicePending($acc->refresh())->lines->pluck('title')->all();

    expect($titles)->toContain('thiritin.com - Renew');
});

it('leaves a line the labeller declines to word alone', function () {
    $acc = labellerAccount();

    Meteric::subscribe()->account($acc)
        ->at(CarbonImmutable::parse('2026-06-01Z'))
        ->add(labellerPrice('vps', 'VPS XL'), 1, null, label: 'vps12345.example')
        ->create();

    $line = Meteric::invoicePending($acc)->lines->first();

    expect($line->title)->toBe('VPS XL - vps12345.example')
        ->and($line->description)->toBe('2026-06-01 to 2026-06-30');
});
