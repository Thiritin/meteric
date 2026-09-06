<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Meteric\Enums\InvoiceSchedule;
use Meteric\Support\Pg;
use Tpetry\PostgresqlEnhanced\Schema\Blueprint;
use Tpetry\PostgresqlEnhanced\Support\Facades\Schema;

return new class extends Migration
{
    // Collective invoicing: an account whose charges accrue through the cycle
    // and are billed on one date instead of one document per event.
    //
    // `collected_through` is the boundary already billed, not a timestamp of
    // the run: a run that happens late still stamps the boundary it billed, so
    // a missed cycle is picked up once and a second run the same day bills
    // nothing.
    public function up(): void
    {
        Schema::table(Pg::table('billing_accounts'), function (Blueprint $table) {
            $table->string('invoice_schedule')->default(InvoiceSchedule::Immediate->value);
            $table->smallInteger('invoice_day')->nullable();
            $table->date('collected_through')->nullable();
        });

        Pg::enumCheck(Pg::table('billing_accounts'), 'invoice_schedule', InvoiceSchedule::class);
        Pg::check(Pg::table('billing_accounts'), 'meteric_accounts_invoice_day', 'invoice_day IS NULL OR invoice_day BETWEEN 1 AND 31');
    }

    public function down(): void
    {
        Schema::table(Pg::table('billing_accounts'), function (Blueprint $table) {
            $table->dropColumn(['invoice_schedule', 'invoice_day', 'collected_through']);
        });
    }
};
