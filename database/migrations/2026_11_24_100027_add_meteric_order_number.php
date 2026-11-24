<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Meteric\Support\DocumentNumbers;
use Meteric\Support\Pg;
use Tpetry\PostgresqlEnhanced\Schema\Blueprint;
use Tpetry\PostgresqlEnhanced\Support\Facades\Schema;

/**
 * The number a person reads an order by. Existing rows are numbered oldest
 * first off the same counter a new order draws on, so the backfill is
 * deterministic, the next order placed continues the series, and a run that
 * stopped half way continues where it left off.
 */
return new class extends Migration
{
    public function up(): void
    {
        $orders = Pg::table('orders');

        Schema::table($orders, function (Blueprint $table) {
            $table->string('number')->nullable();
        });

        DocumentNumbers::backfill($orders, DocumentNumbers::prefix('order', 'ORD'));

        DB::statement("ALTER TABLE {$orders} ALTER COLUMN number SET NOT NULL");

        Schema::table($orders, function (Blueprint $table) {
            $table->unique('number');
        });
    }

    public function down(): void
    {
        Schema::table(Pg::table('orders'), function (Blueprint $table) {
            $table->dropUnique(['number']);
            $table->dropColumn('number');
        });
    }
};
