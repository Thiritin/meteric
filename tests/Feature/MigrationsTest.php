<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('runs all migrations and creates the core tables', function () {
    foreach ([
        'meteric_billing_accounts', 'meteric_products', 'meteric_prices',
        'meteric_meter_dimensions', 'meteric_subscriptions', 'meteric_subscription_items',
        'meteric_addons', 'meteric_item_options', 'meteric_commitments', 'meteric_allowances',
        'meteric_usage_records', 'meteric_billing_periods', 'meteric_charges',
        'meteric_invoices', 'meteric_invoice_lines', 'meteric_credit_notes',
        'meteric_payments', 'meteric_payment_allocations', 'meteric_coupons',
        'meteric_discounts', 'meteric_ledger',
    ] as $table) {
        expect(Schema::hasTable($table))->toBeTrue("missing table {$table}");
    }
});

it('creates tstzrange columns as real ranges', function () {
    $type = DB::selectOne("
        SELECT data_type FROM information_schema.columns
        WHERE table_name = 'meteric_subscriptions' AND column_name = 'current_period'
    ");

    expect($type->data_type)->toBe('tstzrange');
});

it('enforces enum values via check constraint', function () {
    $accountId = insertAccount();

    DB::table('meteric_products')->insert([
        'id' => DB::raw('gen_random_uuid()'),
        'type' => 'vps', 'slug' => 'bad-'.uniqid(), 'name' => 'Bad',
        'pricing_model' => 'not_a_real_model', // violates CHECK
    ]);
})->throws(QueryException::class);

it('rejects an invalid currency format', function () {
    DB::table('meteric_billing_accounts')->insert([
        'id' => DB::raw('gen_random_uuid()'),
        'owner_type' => 'user', 'owner_id' => '1',
        'currency' => 'eur', // lowercase violates ^[A-Z]{3}$
    ]);
})->throws(QueryException::class);

function insertAccount(): string
{
    $id = (string) Str::uuid();
    DB::table('meteric_billing_accounts')->insert([
        'id' => $id, 'owner_type' => 'user', 'owner_id' => '1', 'currency' => 'EUR',
    ]);

    return $id;
}
