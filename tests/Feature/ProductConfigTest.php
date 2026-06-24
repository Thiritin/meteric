<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Meteric\Models\Product;

uses(RefreshDatabase::class);

function makeProduct(array $config): Product
{
    return Product::create([
        'type' => 'vps', 'slug' => 'pc-'.uniqid(), 'name' => 'VPS',
        'pricing_model' => 'fixed', 'config' => $config,
    ]);
}

it('rejects an unknown downgrade policy in product config', function () {
    makeProduct(['downgrade' => 'bogus']);
})->throws(InvalidArgumentException::class);

it('rejects a negative cancel notice in product config', function () {
    makeProduct(['cancel_notice_days' => -5]);
})->throws(InvalidArgumentException::class);

it('accepts valid known keys and passes host keys through', function () {
    $p = makeProduct(['downgrade' => 'discard', 'cancel_notice_days' => 14, 'provisioner' => 'openstack']);

    expect($p->fresh()->config['downgrade'])->toBe('discard')
        ->and($p->fresh()->config['cancel_notice_days'])->toBe(14)
        ->and($p->fresh()->config['provisioner'])->toBe('openstack');  // host's own key untouched
});
