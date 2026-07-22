<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Meteric\Support\Pg;

uses(RefreshDatabase::class);

it('runs all migrations and creates the core tables', function () {
    foreach ([
        'billing_accounts', 'products', 'prices',
        'meter_dimensions', 'subscriptions', 'subscription_items',
        'addons', 'item_options',
        'usage_records', 'billing_periods', 'charges',
        'invoices', 'invoice_lines', 'credit_notes',
        'payments', 'payment_allocations', 'ledger',
    ] as $name) {
        $table = Pg::table($name);
        expect(Schema::hasTable($table))->toBeTrue("missing table {$table}");
    }
});

it('creates tstzrange columns as real ranges', function () {
    $type = DB::selectOne('
        SELECT data_type FROM information_schema.columns
        WHERE table_name = ? AND column_name = ?
    ', [Pg::table('subscriptions'), 'current_period']);

    expect($type->data_type)->toBe('tstzrange');
});

it('enforces enum values via check constraint', function () {
    $accountId = insertAccount();

    DB::table(Pg::table('products'))->insert([
        'id' => DB::raw('gen_random_uuid()'),
        'type' => 'vps', 'slug' => 'bad-'.uniqid(), 'name' => 'Bad',
        'pricing_model' => 'not_a_real_model', // violates CHECK
    ]);
})->throws(QueryException::class);

it('rejects an invalid currency format', function () {
    DB::table(Pg::table('billing_accounts'))->insert([
        'id' => DB::raw('gen_random_uuid()'),
        'owner_type' => 'user', 'owner_id' => '1',
        'currency' => 'eur', // lowercase violates ^[A-Z]{3}$
    ]);
})->throws(QueryException::class);

function insertAccount(): string
{
    $id = (string) Str::uuid();
    DB::table(Pg::table('billing_accounts'))->insert([
        'id' => $id, 'owner_type' => 'user', 'owner_id' => '1', 'currency' => 'EUR',
    ]);

    return $id;
}
