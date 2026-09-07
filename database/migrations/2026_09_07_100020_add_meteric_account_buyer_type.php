<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Meteric\Enums\BuyerType;
use Meteric\Support\Pg;
use Tpetry\PostgresqlEnhanced\Schema\Blueprint;
use Tpetry\PostgresqlEnhanced\Support\Facades\Schema;

return new class extends Migration
{
    // Which body of contract law protects the buyer. Nullable and null by
    // default, including on every account that already exists: only the host
    // can answer it, and an engine that assumed either answer would hold one
    // kind of buyer to terms the other kind's law writes for them.
    public function up(): void
    {
        Schema::table(Pg::table('billing_accounts'), function (Blueprint $table) {
            $table->string('buyer_type')->nullable();
        });

        Pg::enumCheck(Pg::table('billing_accounts'), 'buyer_type', BuyerType::class, nullable: true);
    }

    public function down(): void
    {
        Schema::table(Pg::table('billing_accounts'), function (Blueprint $table) {
            $table->dropColumn('buyer_type');
        });
    }
};
