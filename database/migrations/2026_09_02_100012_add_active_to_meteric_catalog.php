<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Meteric\Support\Pg;
use Tpetry\PostgresqlEnhanced\Schema\Blueprint;
use Tpetry\PostgresqlEnhanced\Support\Facades\Schema;

return new class extends Migration
{
    // Withdraw an option, a value or an addon from sale without deleting it:
    // catalogs skip inactive rows and checkout refuses them, while live items
    // that already reference one keep renewing.
    public function up(): void
    {
        foreach (['product_options', 'product_option_values', 'product_addons'] as $name) {
            Schema::table(Pg::table($name), function (Blueprint $table) {
                $table->boolean('active')->default(true);
            });
        }
    }

    public function down(): void
    {
        foreach (['product_options', 'product_option_values', 'product_addons'] as $name) {
            Schema::table(Pg::table($name), function (Blueprint $table) {
                $table->dropColumn('active');
            });
        }
    }
};
