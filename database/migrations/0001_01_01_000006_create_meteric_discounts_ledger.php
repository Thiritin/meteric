<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Meteric\Enums\DiscountType;
use Meteric\Support\Pg;
use Tpetry\PostgresqlEnhanced\Schema\Blueprint;
use Tpetry\PostgresqlEnhanced\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meteric_coupons', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('code')->unique();
            $table->string('type');
            $table->decimal('value', 12, 4);
            $table->bigInteger('value_minor')->nullable();
            $table->char('currency', 3)->nullable();
            $table->integer('duration_cycles')->nullable();
            $table->integer('max_redemptions')->nullable();
            $table->integer('redeemed_count')->default(0);
            $table->timestampTz('valid_from')->nullable();
            $table->timestampTz('valid_to')->nullable();
            $table->boolean('exclusive')->default(false);
            $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));
            $table->timestampTz('created_at')->useCurrent();
        });
        Pg::enumCheck('meteric_coupons', 'type', DiscountType::class);

        Schema::create('meteric_discounts', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('coupon_id')->nullable()->constrained('meteric_coupons')->nullOnDelete();
            $table->string('target_type');
            $table->string('target_id');
            $table->integer('remaining_cycles')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['target_type', 'target_id']);
        });

        Schema::create('meteric_ledger', function (Blueprint $table) {
            $table->identity(always: true)->primary();
            $table->foreignUuid('account_id')->constrained('meteric_billing_accounts')->restrictOnDelete();
            $table->uuid('txn_id');                 // groups balanced rows
            $table->string('entry');
            $table->bigInteger('debit_minor')->default(0);
            $table->bigInteger('credit_minor')->default(0);
            $table->char('currency', 3);
            $table->string('ref_type')->nullable();
            $table->string('ref_id')->nullable();
            $table->timestampTz('posted_at')->useCurrent();

            $table->index(['account_id', 'posted_at']);
            $table->index('txn_id');
        });
        Pg::currencyCheck('meteric_ledger');
        Pg::check('meteric_ledger', 'meteric_ledger_single_side', 'debit_minor = 0 OR credit_minor = 0');
    }

    public function down(): void
    {
        Schema::dropIfExists('meteric_ledger');
        Schema::dropIfExists('meteric_discounts');
        Schema::dropIfExists('meteric_coupons');
    }
};
