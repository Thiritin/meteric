<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Meteric\Support\Pg;
use Tpetry\PostgresqlEnhanced\Schema\Blueprint;
use Tpetry\PostgresqlEnhanced\Support\Facades\Schema;

/**
 * Per-prefix, per-year document number counters. The invoice driver increments
 * a row atomically (INSERT ... ON CONFLICT DO UPDATE ... RETURNING) inside the
 * issuing transaction, so numbering is gapless and race-free without counting
 * rows in the invoices table (which would include drafts and voids).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create(Pg::table('invoice_numbers'), function (Blueprint $table) {
            $table->string('prefix');
            $table->integer('year');
            $table->bigInteger('seq')->default(0);

            $table->primary(['prefix', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(Pg::table('invoice_numbers'));
    }
};
