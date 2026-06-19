<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Tpetry\PostgresqlEnhanced\Schema\Blueprint;
use Tpetry\PostgresqlEnhanced\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Where the merchant is VAT-registered. Drives WHETHER tax is charged.
        Schema::create('meteric_tax_registrations', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->char('country', 2);                 // ISO-3166 (or 'EU' marker for OSS)
            $table->string('scheme')->default('standard'); // standard | eu_oss | ch_vat | uk_vat | ...
            $table->string('number')->nullable();        // VAT/registration number
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));
            $table->timestampsTz();

            $table->index('country');
        });

        // Editable rate table. Drives HOW MUCH. EU rows seeded from ibericode via
        // `meteric:vat-sync`; non-EU (CH, UK, …) added manually (source='manual').
        Schema::create('meteric_tax_rates', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->char('country', 2);
            $table->string('region')->nullable();        // for sub-national (US states) later
            $table->string('category')->default('standard'); // standard | reduced | lodging | ...
            $table->decimal('rate', 8, 6);               // fraction, e.g. 0.081000
            $table->date('effective_from');
            $table->date('effective_to')->nullable();    // null = current
            $table->string('source')->default('manual'); // manual | ibericode
            $table->string('label')->nullable();
            $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));
            $table->timestampsTz();

            $table->index(['country', 'category', 'effective_from']);
            // One current rate per country+region+category.
            $table->uniqueIndex(['country', 'region', 'category'])->where('effective_to IS NULL');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meteric_tax_rates');
        Schema::dropIfExists('meteric_tax_registrations');
    }
};
