<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Meteric\Enums\BillingMode;
use Meteric\Enums\CommitmentState;
use Meteric\Enums\Interval;
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
        Schema::create('meteric_subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('account_id')->constrained('meteric_billing_accounts')->restrictOnDelete();
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
        Pg::currencyCheck('meteric_subscriptions');
        Pg::enumCheck('meteric_subscriptions', 'state', SubscriptionState::class);
        Pg::check('meteric_subscriptions', 'meteric_subs_anchor_day', 'anchor_day IS NULL OR anchor_day BETWEEN 1 AND 31');

        Schema::create('meteric_subscription_items', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('subscription_id')->constrained('meteric_subscriptions')->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained('meteric_products')->restrictOnDelete();
            $table->foreignUuid('price_id')->constrained('meteric_prices')->restrictOnDelete();
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
        Pg::enumCheck('meteric_subscription_items', 'state', ItemState::class);
        Pg::enumCheck('meteric_subscription_items', 'billing_mode', BillingMode::class, nullable: true);
        Pg::check('meteric_subscription_items', 'meteric_items_qty_nonneg', 'quantity >= 0');

        Schema::create('meteric_addons', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('item_id')->constrained('meteric_subscription_items')->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained('meteric_products')->restrictOnDelete();
            $table->foreignUuid('price_id')->constrained('meteric_prices')->restrictOnDelete();
            $table->string('group_key')->nullable();
            $table->decimal('quantity', 20, 6)->default(1);
            $table->string('state')->default(ItemState::Active->value);
            $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));
            $table->timestampsTz();

            // At most one active addon per group per item.
            $table->uniqueIndex(['item_id', 'group_key'])->where("state = 'active' AND group_key IS NOT NULL");
        });
        Pg::enumCheck('meteric_addons', 'state', ItemState::class);

        Schema::create('meteric_item_options', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('item_id')->constrained('meteric_subscription_items')->cascadeOnDelete();
            $table->string('key');
            $table->string('type');
            $table->string('value');
            $table->foreignUuid('price_id')->nullable()->constrained('meteric_prices')->restrictOnDelete();
            $table->decimal('quantity', 20, 6)->default(1);
            $table->timestampsTz();

            $table->unique(['item_id', 'key']);
        });
        Pg::enumCheck('meteric_item_options', 'type', OptionType::class);

        Schema::create('meteric_commitments', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('item_id')->constrained('meteric_subscription_items')->cascadeOnDelete();
            $table->string('term_interval');
            $table->integer('term_count');
            $table->bigInteger('upfront_minor')->default(0);
            $table->bigInteger('rate_minor');
            $table->char('currency', 3);
            $table->timestampTzRange('term');
            $table->jsonb('early_term')->default(DB::raw("'{}'::jsonb"));
            $table->string('state')->default(CommitmentState::Active->value);
            $table->timestampTz('created_at')->useCurrent();

            $table->index('item_id');
        });
        Pg::currencyCheck('meteric_commitments');
        Pg::enumCheck('meteric_commitments', 'term_interval', Interval::class);
        Pg::enumCheck('meteric_commitments', 'state', CommitmentState::class);
        Pg::check('meteric_commitments', 'meteric_commit_term_pos', 'term_count > 0');

        Schema::create('meteric_allowances', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('item_id')->constrained('meteric_subscription_items')->cascadeOnDelete();
            $table->foreignUuid('dimension_id')->constrained('meteric_meter_dimensions')->cascadeOnDelete();
            $table->decimal('included_qty', 20, 6);
            $table->timestampTzRange('period');
            $table->decimal('consumed_qty', 20, 6)->default(0);
            $table->string('shared_pool')->nullable();

            $table->unique(['item_id', 'dimension_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meteric_allowances');
        Schema::dropIfExists('meteric_commitments');
        Schema::dropIfExists('meteric_item_options');
        Schema::dropIfExists('meteric_addons');
        Schema::dropIfExists('meteric_subscription_items');
        Schema::dropIfExists('meteric_subscriptions');
    }
};
