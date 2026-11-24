<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Meteric\Support\Pg;
use Tpetry\PostgresqlEnhanced\Support\Facades\Schema;

/**
 * The counter table now numbers orders as well as invoices and credit notes, so
 * it is named after what it holds. Nothing but the name changes: the columns,
 * the primary key and the rows carry over, so the sequences continue where they
 * stood.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable(Pg::table('invoice_numbers'))) {
            Schema::rename(Pg::table('invoice_numbers'), Pg::table('document_numbers'));
        }
    }

    public function down(): void
    {
        if (Schema::hasTable(Pg::table('document_numbers'))) {
            Schema::rename(Pg::table('document_numbers'), Pg::table('invoice_numbers'));
        }
    }
};
