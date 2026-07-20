<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Meteric\Enums\BillingMode;
use Meteric\Enums\ItemState;
use Meteric\Enums\OptionType;
use Meteric\Enums\SubscriptionState;
use Meteric\Support\Pg;
use Tpetry\PostgresqlEnhanced\Schema\Blueprint;
use Tpetry\PostgresqlEnhanced\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(Pg::table('subscriptions'), function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('account_id')->constrained(Pg::table('billing_accounts'))->restrictOnDelete();
            $table->string('customer_type');
            $table->string('customer_id');
            $table->char('currency', 3);
            $table->string('state')->default(SubscriptionState::Incomplete->value);
            $table->string('anchor_mode')->default('signup');
            $table->smallInteger('anchor_day')->nullable();
            $table->string('first_period')->default('prorate_only');
            $table->timestampTzRange('current_period')->nullable();
            $table->timestampTz('trial_end')->nullable();
            $table->timestampTz('canceled_at')->nullable();
            $table->timestampTz('cancel_at')->nullable();
            $table->integer('version')->default(0);
            $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));
            $table->timestampsTz();

            $table->index('account_id');
            $table->index(['customer_type', 'customer_id']);
            $table->index('(upper(current_period))', 'meteric_subs_due_idx')->where("state IN ('active','trialing','past_due')");
        });
        Pg::currencyCheck(Pg::table('subscriptions'));
        Pg::enumCheck(Pg::table('subscriptions'), 'state', SubscriptionState::class);
        Pg::check(Pg::table('subscriptions'), 'meteric_subs_anchor_day', 'anchor_day IS NULL OR anchor_day BETWEEN 1 AND 31');

        Schema::create(Pg::table('subscription_items'), function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('subscription_id')->constrained(Pg::table('subscriptions'))->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained(Pg::table('products'))->restrictOnDelete();
            $table->foreignUuid('price_id')->constrained(Pg::table('prices'))->restrictOnDelete();
            $table->string('resource_type')->nullable();
            $table->string('resource_id')->nullable();
            $table->string('label')->nullable();              // line title on invoices (e.g. the resource hostname)
            $table->string('group')->nullable();              // invoice section heading (e.g. Domains, Usage)
            $table->decimal('quantity', 20, 6)->default(1);
            $table->string('billing_mode')->nullable();
            $table->string('state')->default(ItemState::Pending->value);
            $table->timestampTzRange('current_period')->nullable();
            $table->timestampTz('activated_at')->nullable();
            $table->timestampTz('ends_at')->nullable();
            $table->jsonb('pending_change')->nullable();
            $table->integer('version')->default(0);
            $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));
            $table->timestampsTz();

            $table->index('subscription_id');
            $table->index(['resource_type', 'resource_id']);
            $table->index('(upper(current_period))', 'meteric_items_due_idx')->where("state = 'active'");
        });
        Pg::enumCheck(Pg::table('subscription_items'), 'state', ItemState::class);
        Pg::enumCheck(Pg::table('subscription_items'), 'billing_mode', BillingMode::class, nullable: true);
        Pg::check(Pg::table('subscription_items'), 'meteric_items_qty_nonneg', 'quantity >= 0');

        Schema::create(Pg::table('addons'), function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('item_id')->constrained(Pg::table('subscription_items'))->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained(Pg::table('products'))->restrictOnDelete();
            $table->foreignUuid('price_id')->constrained(Pg::table('prices'))->restrictOnDelete();
            $table->string('group_key')->nullable();
            $table->decimal('quantity', 20, 6)->default(1);
            $table->string('state')->default(ItemState::Active->value);
            $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));
            $table->timestampsTz();

            // At most one active addon per group per item.
            $table->uniqueIndex(['item_id', 'group_key'])->where("state = 'active' AND group_key IS NOT NULL");
        });
        Pg::enumCheck(Pg::table('addons'), 'state', ItemState::class);

        Schema::create(Pg::table('item_options'), function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('item_id')->constrained(Pg::table('subscription_items'))->cascadeOnDelete();
            $table->string('key');
            $table->string('type');
            $table->string('value');                          // raw value for provisioning (e.g. 1024)
            $table->string('label')->nullable();              // display value (e.g. "1 GB RAM")
            $table->foreignUuid('price_id')->nullable()->constrained(Pg::table('prices'))->restrictOnDelete();
            $table->decimal('quantity', 20, 6)->default(1);
            $table->decimal('min_qty', 20, 6)->nullable();   // quantity bounds (WHMCS-style)
            $table->decimal('max_qty', 20, 6)->nullable();
            $table->timestampsTz();

            $table->unique(['item_id', 'key']);
        });
        Pg::enumCheck(Pg::table('item_options'), 'type', OptionType::class);
    }

    public function down(): void
    {
        Schema::dropIfExists(Pg::table('item_options'));
        Schema::dropIfExists(Pg::table('addons'));
        Schema::dropIfExists(Pg::table('subscription_items'));
        Schema::dropIfExists(Pg::table('subscriptions'));
    }
};
