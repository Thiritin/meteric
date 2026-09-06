<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Meteric\Enums\InvoiceSplit;
use Meteric\Support\Pg;
use Tpetry\PostgresqlEnhanced\Schema\Blueprint;
use Tpetry\PostgresqlEnhanced\Support\Facades\Schema;

return new class extends Migration
{
    // How many documents the billable pool becomes. Orthogonal to
    // `invoice_schedule`, which says when it becomes one: an account can be
    // billed monthly and still want one invoice per subscription.
    public function up(): void
    {
        Schema::table(Pg::table('billing_accounts'), function (Blueprint $table) {
            $table->string('invoice_split')->default(InvoiceSplit::Pooled->value);
        });

        Pg::enumCheck(Pg::table('billing_accounts'), 'invoice_split', InvoiceSplit::class);
    }

    public function down(): void
    {
        Schema::table(Pg::table('billing_accounts'), function (Blueprint $table) {
            $table->dropColumn('invoice_split');
        });
    }
};
