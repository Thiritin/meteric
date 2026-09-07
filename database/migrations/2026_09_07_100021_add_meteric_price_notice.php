<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Meteric\Support\Pg;
use Tpetry\PostgresqlEnhanced\Schema\Blueprint;
use Tpetry\PostgresqlEnhanced\Support\Facades\Schema;

return new class extends Migration
{
    // The notice a cancellation needs, on the price rather than only on the
    // product. A monthly price and a yearly price of one product do not share
    // a notice period any more than they share a minimum term, and the notice
    // a sale was made under is the row the subscription item points at.
    //
    // Null takes the product's figure, so a catalog written before this
    // migration answers exactly as it did.
    public function up(): void
    {
        Schema::table(Pg::table('prices'), function (Blueprint $table) {
            $table->integer('cancel_notice_days')->nullable();
        });

        Pg::check(Pg::table('prices'), 'meteric_prices_notice_nonneg', 'cancel_notice_days IS NULL OR cancel_notice_days >= 0');
    }

    public function down(): void
    {
        Schema::table(Pg::table('prices'), function (Blueprint $table) {
            $table->dropColumn('cancel_notice_days');
        });
    }
};
