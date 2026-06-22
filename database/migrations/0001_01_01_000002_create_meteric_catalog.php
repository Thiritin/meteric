<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Meteric\Enums\Aggregation;
use Meteric\Enums\BillingMode;
use Meteric\Enums\PricePurpose;
use Meteric\Enums\PricingModel;
use Meteric\Support\Pg;
use Tpetry\PostgresqlEnhanced\Schema\Blueprint;
use Tpetry\PostgresqlEnhanced\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meteric_billing_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('parent_id')->nullable();
            $table->string('owner_type');
            $table->string('owner_id');
            $table->char('currency', 3);
            $table->jsonb('tax_profile')->default(DB::raw("'{}'::jsonb"));
            $table->bigInteger('balance_minor')->default(0);
            $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));
            $table->timestampsTz();

            $table->index(['owner_type', 'owner_id']);
            $table->index('parent_id');
        });
        // Self-referencing FK added after the table (and its PK) exists.
        Schema::table('meteric_billing_accounts', function (Blueprint $table) {
            $table->foreign('parent_id')->references('id')->on('meteric_billing_accounts')->restrictOnDelete();
        });
        Pg::currencyCheck('meteric_billing_accounts');

        Schema::create('meteric_products', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('billable_type')->nullable();
            $table->string('billable_id')->nullable();
            $table->string('type');
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('pricing_model');
            $table->boolean('is_proratable')->default(true);
            $table->jsonb('config')->default(DB::raw("'{}'::jsonb"));
            $table->boolean('active')->default(true);
            $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));
            $table->timestampsTz();

            $table->index(['billable_type', 'billable_id']);
            $table->index('type')->where('active');
        });
        Pg::enumCheck('meteric_products', 'pricing_model', PricingModel::class);

        Schema::create('meteric_prices', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('product_id')->constrained('meteric_products')->cascadeOnDelete();
            $table->char('currency', 3);
            $table->bigInteger('amount_minor')->default(0);          // flat/base amount (integer minor)
            $table->decimal('unit_rate', 20, 8)->nullable();         // per-unit/usage rate (major units, sub-cent)
            $table->string('purpose')->default(PricePurpose::Recurring->value);
            $table->string('pricing_model');
            $table->string('interval')->nullable();
            $table->integer('interval_count')->nullable();
            $table->string('billing_mode')->default(BillingMode::InAdvance->value);
            $table->bigInteger('setup_fee_minor')->default(0);
            $table->bigInteger('cap_minor')->nullable();             // hourly monthly cap
            $table->bigInteger('min_charge_minor')->default(0);
            $table->jsonb('tiers')->default(DB::raw("'[]'::jsonb"));
            $table->boolean('tax_inclusive')->default(false);
            $table->timestampTz('valid_from')->useCurrent();
            $table->timestampTz('valid_to')->nullable();             // null = current; old rows kept (grandfathering)
            $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));
            $table->timestampsTz();

            $table->index(['product_id', 'currency', 'purpose'])->where('valid_to IS NULL');
            $table->index('tiers')->algorithm('gin');
        });
        Pg::currencyCheck('meteric_prices');
        Pg::enumCheck('meteric_prices', 'purpose', PricePurpose::class);
        Pg::enumCheck('meteric_prices', 'pricing_model', PricingModel::class);
        Pg::enumCheck('meteric_prices', 'billing_mode', BillingMode::class);
        Pg::check('meteric_prices', 'meteric_prices_amount_nonneg', 'amount_minor >= 0');
        Pg::check('meteric_prices', 'meteric_prices_rate_nonneg', 'unit_rate IS NULL OR unit_rate >= 0');

        Schema::create('meteric_meter_dimensions', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('product_id')->constrained('meteric_products')->cascadeOnDelete();
            $table->string('key');
            $table->string('unit');
            $table->string('aggregation')->default(Aggregation::Sum->value);
            $table->decimal('rate', 20, 8);                          // price per unit, or per block when block_size is set
            $table->decimal('block_size', 20, 6)->nullable();        // bill per block of this many units (ceil); null = per unit
            $table->char('currency', 3);
            $table->decimal('included_qty', 20, 6)->default(0);      // free allowance per cycle
            $table->bigInteger('cap_minor')->nullable();
            $table->jsonb('tiers')->default(DB::raw("'[]'::jsonb"));
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['product_id', 'key']);
        });
        Pg::currencyCheck('meteric_meter_dimensions');
        Pg::enumCheck('meteric_meter_dimensions', 'aggregation', Aggregation::class);
        Pg::check('meteric_meter_dimensions', 'meteric_md_rate_nonneg', 'rate >= 0');

        // Configurable options: a product declares options (dropdown/radio/qty/toggle),
        // each with allowed values that point at a Price (per-term, tiered, setup fee).
        Schema::create('meteric_product_options', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('product_id')->constrained('meteric_products')->cascadeOnDelete();
            $table->string('key');
            $table->string('label')->nullable();
            $table->string('type');                                  // OptionType: quantity | choice | toggle
            $table->boolean('required')->default(false);
            $table->decimal('min_qty', 20, 6)->nullable();           // quantity options
            $table->decimal('max_qty', 20, 6)->nullable();
            $table->integer('sort')->default(0);
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['product_id', 'key']);
        });

        Schema::create('meteric_product_option_values', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('option_id')->constrained('meteric_product_options')->cascadeOnDelete();
            $table->string('value');
            $table->string('label')->nullable();
            $table->foreignUuid('price_id')->nullable()->constrained('meteric_prices')->restrictOnDelete();
            $table->integer('sort')->default(0);
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['option_id', 'value']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meteric_product_option_values');
        Schema::dropIfExists('meteric_product_options');
        Schema::dropIfExists('meteric_meter_dimensions');
        Schema::dropIfExists('meteric_prices');
        Schema::dropIfExists('meteric_products');
        Schema::dropIfExists('meteric_billing_accounts');
    }
};
