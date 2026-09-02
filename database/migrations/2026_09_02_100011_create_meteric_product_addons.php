<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Meteric\Support\Pg;
use Tpetry\PostgresqlEnhanced\Schema\Blueprint;
use Tpetry\PostgresqlEnhanced\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Addon catalog: which addon products a base product may be booked with,
        // whether one is required, and how many. The price is resolved from the
        // addon product at checkout for the base line's currency and term.
        Schema::create(Pg::table('product_addons'), function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('product_id')->constrained(Pg::table('products'))->cascadeOnDelete();
            $table->foreignUuid('addon_product_id')->constrained(Pg::table('products'))->cascadeOnDelete();
            $table->string('group_key')->nullable();                 // mutually exclusive within a group
            $table->boolean('required')->default(false);
            $table->decimal('min_qty', 20, 6)->nullable();
            $table->decimal('max_qty', 20, 6)->nullable();
            $table->integer('sort')->default(0);
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['product_id', 'addon_product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(Pg::table('product_addons'));
    }
};
