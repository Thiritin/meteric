<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Meteric\Contracts\Clock;
use Meteric\Facades\Meteric;
use Meteric\Models\BillingAccount;
use Meteric\Models\Order;
use Meteric\Models\Price;
use Meteric\Models\Product;
use Meteric\Support\DocumentNumbers;
use Meteric\Support\FrozenClock;
use Meteric\Support\Pg;

uses(RefreshDatabase::class);

function numberedAccount(): BillingAccount
{
    return BillingAccount::create(['owner_type' => 'user', 'owner_id' => '1', 'currency' => 'EUR']);
}

function numberedPrice(int $minor = 1000): Price
{
    $product = Product::create(['type' => 'vps', 'slug' => 'vps-'.uniqid(), 'name' => 'Hosting', 'pricing_model' => 'fixed']);

    return Price::create([
        'product_id' => $product->id, 'currency' => 'EUR', 'amount_minor' => $minor,
        'pricing_model' => 'fixed', 'interval' => 'month', 'interval_count' => 1,
    ]);
}

function numberedOrder(): Order
{
    return Meteric::createOrder()->account(numberedAccount())->add(numberedPrice(), 1)->create();
}

it('numbers an order on creation, sequentially within the year', function () {
    app()->instance(Clock::class, FrozenClock::at('2026-03-04T00:00:00Z'));

    expect(numberedOrder()->number)->toBe('ORD-2026-000001')
        ->and(numberedOrder()->number)->toBe('ORD-2026-000002');
});

it('restarts the sequence in a new year and keeps the old numbers', function () {
    app()->instance(Clock::class, FrozenClock::at('2026-12-31T00:00:00Z'));
    $last = numberedOrder();

    app()->instance(Clock::class, FrozenClock::at('2027-01-01T00:00:00Z'));
    $first = numberedOrder();

    expect($last->number)->toBe('ORD-2026-000001')
        ->and($first->number)->toBe('ORD-2027-000001');
});

it('does not reuse the number of a canceled order', function () {
    app()->instance(Clock::class, FrozenClock::at('2026-03-04T00:00:00Z'));

    $canceled = numberedOrder();
    Meteric::cancelOrder($canceled);

    expect(numberedOrder()->number)->toBe('ORD-2026-000002')
        ->and($canceled->fresh()->number)->toBe('ORD-2026-000001');
});

it('leaves the counter alone when the order it numbered rolled back', function () {
    app()->instance(Clock::class, FrozenClock::at('2026-03-04T00:00:00Z'));
    $account = numberedAccount();
    $price = numberedPrice();

    try {
        DB::transaction(function () use ($account, $price) {
            Meteric::createOrder()->account($account)->add($price, 1)->create();

            throw new RuntimeException('abandoned');
        });
    } catch (RuntimeException) {
        // The order and its number go back together.
    }

    expect(Order::count())->toBe(0)
        ->and(numberedOrder()->number)->toBe('ORD-2026-000001');
});

it('takes the prefix and the shape from the configuration', function () {
    config([
        'meteric.documents.prefixes.order' => 'BE',
        'meteric.documents.number_format' => '%s/%d/%04d',
    ]);
    app()->instance(Clock::class, FrozenClock::at('2026-03-04T00:00:00Z'));

    expect(numberedOrder()->number)->toBe('BE/2026/0001');
});

/**
 * Two callers cannot be run at the same instant from one test, so the lock is
 * proved the other way round: this test's own number is taken and left
 * uncommitted inside the test transaction, and a second connection is given a
 * quarter of a second to take the next one. It does not come back with a
 * number, which is what matters - it waits on the row rather than reading
 * around it and handing the same number out twice.
 */
it('serializes two callers on the counter row rather than colliding', function () {
    $mine = DocumentNumbers::next('TST', 2026);

    config(['database.connections.rival' => config('database.connections.pgsql')]);
    DB::connection('rival')->statement("SET statement_timeout = '250ms'");
    DB::setDefaultConnection('rival');

    try {
        expect(fn () => DocumentNumbers::next('TST', 2026))
            ->toThrow(QueryException::class, 'canceling statement due to statement timeout');
    } finally {
        DB::setDefaultConnection('pgsql');
        DB::purge('rival');
    }

    expect($mine)->toBe('TST-2026-000001');
});

it('backfills rows that have no number, deterministically and oldest first', function () {
    $table = Pg::table('number_backfill_probe');

    DB::statement("CREATE TEMPORARY TABLE {$table} (id text primary key, created_at timestamptz, number text)");
    DB::table($table)->insert([
        ['id' => 'b', 'created_at' => '2026-02-01T00:00:00Z', 'number' => null],
        ['id' => 'a', 'created_at' => '2026-01-01T00:00:00Z', 'number' => null],
        ['id' => 'tie-2', 'created_at' => '2026-03-01T00:00:00Z', 'number' => null],
        ['id' => 'tie-1', 'created_at' => '2026-03-01T00:00:00Z', 'number' => null],
        ['id' => 'later', 'created_at' => '2027-01-01T00:00:00Z', 'number' => null],
    ]);

    DocumentNumbers::backfill($table, 'ORD');
    $first = DB::table($table)->orderBy('id')->pluck('number', 'id')->all();

    expect($first)->toBe([
        'a' => 'ORD-2026-000001',
        'b' => 'ORD-2026-000002',
        'later' => 'ORD-2027-000001',
        'tie-1' => 'ORD-2026-000003',
        'tie-2' => 'ORD-2026-000004',
    ]);

    // A second run finds nothing to number and moves nothing.
    DocumentNumbers::backfill($table, 'ORD');

    expect(DB::table($table)->orderBy('id')->pluck('number', 'id')->all())->toBe($first);

    // And it left the counters where a live order can carry on from.
    expect(DocumentNumbers::next('ORD', 2026))->toBe('ORD-2026-000005');
});

it('numbers an order written straight to the model, not only through the builder', function () {
    app()->instance(Clock::class, FrozenClock::at('2026-03-04T00:00:00Z'));

    $order = Order::create([
        'account_id' => numberedAccount()->id,
        'customer_type' => 'user',
        'customer_id' => '1',
        'currency' => 'EUR',
        'state' => 'pending',
        'contents' => [],
        'quote_snapshot' => [],
        'token' => 'tok-'.uniqid(),
    ]);

    expect($order->number)->toBe('ORD-2026-000001');
});
