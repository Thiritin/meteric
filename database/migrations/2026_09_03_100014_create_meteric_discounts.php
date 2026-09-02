<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Meteric\Enums\DiscountKind;
use Meteric\Enums\DiscountState;
use Meteric\Enums\DiscountTarget;
use Meteric\Support\Pg;
use Tpetry\PostgresqlEnhanced\Schema\Blueprint;
use Tpetry\PostgresqlEnhanced\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A standing reduction on a subscription item, spent one billed period
        // at a time. Prices cannot go negative, so this is the only place a
        // recurring reduction can live.
        Schema::create(Pg::table('discounts'), function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('subscription_id')->constrained(Pg::table('subscriptions'))->cascadeOnDelete();
            $table->foreignUuid('item_id')->nullable()->constrained(Pg::table('subscription_items'))->cascadeOnDelete();
            $table->string('kind');
            $table->decimal('percent', 9, 6)->nullable();
            $table->bigInteger('amount_minor')->nullable();
            $table->char('currency', 3)->nullable();
            $table->string('target')->default(DiscountTarget::Line->value);
            $table->string('label');
            $table->integer('terms_total')->nullable();
            $table->integer('terms_used')->default(0);
            $table->string('state')->default(DiscountState::Active->value);
            $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));
            $table->timestampsTz();

            $table->index(['item_id', 'state']);
            $table->index(['subscription_id', 'state']);
        });

        Pg::enumCheck(Pg::table('discounts'), 'kind', DiscountKind::class);
        Pg::enumCheck(Pg::table('discounts'), 'target', DiscountTarget::class);
        Pg::enumCheck(Pg::table('discounts'), 'state', DiscountState::class);
        Pg::check(Pg::table('discounts'), 'meteric_discounts_percent', "kind <> 'percent' OR (percent IS NOT NULL AND percent > 0 AND percent <= 100)");
        Pg::check(Pg::table('discounts'), 'meteric_discounts_fixed', "kind <> 'fixed' OR (amount_minor IS NOT NULL AND amount_minor > 0 AND currency IS NOT NULL)");
        Pg::check(Pg::table('discounts'), 'meteric_discounts_terms', 'terms_total IS NULL OR terms_total > 0');
        Pg::check(Pg::table('discounts'), 'meteric_discounts_used', 'terms_used >= 0');
    }

    public function down(): void
    {
        Schema::dropIfExists(Pg::table('discounts'));
    }
};
