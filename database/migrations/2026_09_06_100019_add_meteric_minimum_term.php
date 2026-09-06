<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Meteric\Support\Pg;
use Tpetry\PostgresqlEnhanced\Schema\Blueprint;
use Tpetry\PostgresqlEnhanced\Support\Facades\Schema;

return new class extends Migration
{
    // A minimum term: the number of periods a subscription is committed for
    // before it may be cancelled.
    //
    // The catalog value is what a new sale agrees to; the item's is what one
    // customer did agree to, frozen at signup. Changing the catalog therefore
    // never shortens or lengthens a contract already sold, and an item created
    // before this migration carries null and is committed to nothing.
    public function up(): void
    {
        Schema::table(Pg::table('prices'), function (Blueprint $table) {
            $table->integer('minimum_term_periods')->nullable();
        });

        Schema::table(Pg::table('subscription_items'), function (Blueprint $table) {
            $table->integer('minimum_term_periods')->nullable();
            $table->timestampTz('committed_until')->nullable();
        });

        Pg::check(Pg::table('prices'), 'meteric_prices_min_term_nonneg', 'minimum_term_periods IS NULL OR minimum_term_periods >= 0');
        Pg::check(Pg::table('subscription_items'), 'meteric_items_min_term_nonneg', 'minimum_term_periods IS NULL OR minimum_term_periods >= 0');
    }

    public function down(): void
    {
        Schema::table(Pg::table('prices'), function (Blueprint $table) {
            $table->dropColumn('minimum_term_periods');
        });

        Schema::table(Pg::table('subscription_items'), function (Blueprint $table) {
            $table->dropColumn(['minimum_term_periods', 'committed_until']);
        });
    }
};
