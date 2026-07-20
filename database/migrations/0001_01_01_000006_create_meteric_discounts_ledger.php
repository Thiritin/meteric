<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Meteric\Support\Pg;
use Tpetry\PostgresqlEnhanced\Schema\Blueprint;
use Tpetry\PostgresqlEnhanced\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(Pg::table('ledger'), function (Blueprint $table) {
            $table->identity(always: true)->primary();
            $table->foreignUuid('account_id')->constrained(Pg::table('billing_accounts'))->restrictOnDelete();
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
        Pg::currencyCheck(Pg::table('ledger'));
        Pg::check(Pg::table('ledger'), 'meteric_ledger_single_side', 'debit_minor = 0 OR credit_minor = 0');
    }

    public function down(): void
    {
        Schema::dropIfExists(Pg::table('ledger'));
    }
};
