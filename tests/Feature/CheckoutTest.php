<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Meteric\Enums\InvoiceState;
use Meteric\Facades\Meteric;
use Meteric\Models\BillingAccount;
use Meteric\Models\Price;
use Meteric\Models\Product;

uses(RefreshDatabase::class);

function checkoutPrice(int $minor = 1000): Price
{
    $product = Product::create(['type' => 'vps', 'slug' => 'vps-'.uniqid(), 'name' => 'VPS', 'pricing_model' => 'fixed']);

    return Price::create([
        'product_id' => $product->id, 'currency' => 'EUR', 'amount_minor' => $minor,
        'pricing_model' => 'fixed', 'interval' => 'month', 'interval_count' => 1,
    ]);
}

it('creates a subscription and invoices it immediately', function () {
    $acc = BillingAccount::create(['owner_type' => 'user', 'owner_id' => '1', 'currency' => 'EUR']);

    $checkout = Meteric::subscribe()
        ->account($acc)
        ->at(CarbonImmutable::parse('2026-06-01T00:00:00Z'))
        ->add(checkoutPrice(1000), 1)
        ->checkout();

    expect($checkout->subscription->exists)->toBeTrue()
        ->and($checkout->invoice)->not->toBeNull()
        ->and($checkout->invoice->state)->toBe(InvoiceState::Open)
        ->and($checkout->invoice->subtotal_minor)->toBe(1000);
});
