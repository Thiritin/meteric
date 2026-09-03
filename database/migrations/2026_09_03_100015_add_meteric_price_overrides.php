<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Meteric\Enums\PriceScope;
use Meteric\Support\Pg;

/**
 * A per-item price override: one subscription item billing an amount its
 * product does not publish.
 *
 * The override is a price row of its own rather than an amount column on the
 * item, so that everything which already asks an item for its price keeps
 * working untouched - proration, plan swaps, the accruer, checkout and the
 * relative addon prices that compute against the item's base. An amount column
 * would have had to be honoured at every one of those call sites, and the one
 * that forgot would be a silent money bug.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table(Pg::table('prices'), function (Blueprint $table) {
            $table->string('scope')->default(PriceScope::Catalog->value)->after('purpose');
        });

        Pg::enumCheck(Pg::table('prices'), 'scope', PriceScope::class);

        Schema::table(Pg::table('subscription_items'), function (Blueprint $table) {
            // restrictOnDelete, not cascade: an invoice line written against an
            // override has to resolve years later, so the row is kept for
            // history and never deleted when the override is cleared.
            $table->foreignUuid('price_override_id')
                ->nullable()
                ->after('price_id')
                ->constrained(Pg::table('prices'))
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table(Pg::table('subscription_items'), function (Blueprint $table) {
            $table->dropConstrainedForeignId('price_override_id');
        });

        $prices = Pg::table('prices');
        DB::statement("ALTER TABLE {$prices} DROP CONSTRAINT IF EXISTS {$prices}_scope_check");

        Schema::table(Pg::table('prices'), function (Blueprint $table) {
            $table->dropColumn('scope');
        });
    }
};
