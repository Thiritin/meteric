<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Meteric\Support\Pg;
use Tpetry\PostgresqlEnhanced\Schema\Blueprint;
use Tpetry\PostgresqlEnhanced\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Line-level credit notes: each line reverses part of one invoice line
        // at that line's own tax, so a mixed-rate invoice credits exact VAT.
        Schema::create(Pg::table('credit_note_lines'), function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('credit_note_id')->constrained(Pg::table('credit_notes'))->cascadeOnDelete();
            $table->foreignUuid('invoice_line_id')->nullable()->constrained(Pg::table('invoice_lines'))->restrictOnDelete();
            $table->string('title')->nullable();
            $table->bigInteger('net_minor');
            $table->bigInteger('tax_minor')->default(0);
            $table->decimal('tax_rate', 7, 4)->default(0);
            $table->bigInteger('gross_minor');
            $table->integer('sort')->default(0);
            $table->timestampTz('created_at')->useCurrent();

            $table->index('invoice_line_id');
        });
        Pg::check(Pg::table('credit_note_lines'), 'meteric_cn_lines_net_pos', 'net_minor > 0');

        // Money going back out against a payment. Payments and allocations stay
        // as they were; a refund is its own positive row.
        Schema::create(Pg::table('refunds'), function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('payment_id')->constrained(Pg::table('payments'))->restrictOnDelete();
            $table->foreignUuid('credit_note_id')->nullable()->constrained(Pg::table('credit_notes'))->restrictOnDelete();
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('reference')->nullable()->unique();
            $table->timestampTz('refunded_at')->useCurrent();
            $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));
            $table->timestampTz('created_at')->useCurrent();

            $table->index('payment_id');
        });
        Pg::currencyCheck(Pg::table('refunds'));
        Pg::check(Pg::table('refunds'), 'meteric_refunds_amount_pos', 'amount_minor > 0');
    }

    public function down(): void
    {
        Schema::dropIfExists(Pg::table('refunds'));
        Schema::dropIfExists(Pg::table('credit_note_lines'));
    }
};
