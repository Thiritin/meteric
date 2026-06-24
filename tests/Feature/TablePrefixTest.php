<?php

declare(strict_types=1);

use Meteric\Models\Invoice;
use Meteric\Models\Subscription;
use Meteric\Support\Pg;

it('derives the default table prefix from config', function () {
    expect(Pg::table('invoices'))->toBe('meteric_invoices');
    expect((new Subscription)->getTable())->toBe('meteric_subscriptions');
});

it('drives every table name from the configured prefix', function () {
    config(['meteric.schema.prefix' => 'tnt_']);

    expect(Pg::table('invoices'))->toBe('tnt_invoices');
    expect((new Subscription)->getTable())->toBe('tnt_subscriptions');
    expect((new Invoice)->getTable())->toBe('tnt_invoices');
});

it('yields unprefixed names when the prefix is empty', function () {
    config(['meteric.schema.prefix' => '']);

    expect(Pg::table('subscriptions'))->toBe('subscriptions');
    expect((new Subscription)->getTable())->toBe('subscriptions');
});
