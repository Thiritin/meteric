<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Meteric\Enums\CreditState;
use Meteric\Enums\InvoiceState;
use Meteric\Enums\LineKind;
use Meteric\Support\Pg;
use Tpetry\PostgresqlEnhanced\Schema\Blueprint;
use Tpetry\PostgresqlEnhanced\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(Pg::table('invoices'), function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('account_id')->constrained(Pg::table('billing_accounts'))->restrictOnDelete();
            $table->string('customer_type');
            $table->string('customer_id');
            $table->string('number')->nullable();
            $table->string('driver')->default('database');
            $table->string('external_id')->nullable();
            $table->string('external_url')->nullable();
            $table->string('state')->default(InvoiceState::Draft->value);
            $table->char('currency', 3);
            $table->bigInteger('subtotal_minor')->default(0);
            $table->bigInteger('tax_minor')->default(0);
            $table->bigInteger('total_minor')->default(0);
            $table->bigInteger('paid_minor')->default(0);
            $table->timestampTz('issued_at')->nullable();
            $table->timestampTz('due_at')->nullable();
            $table->timestampTz('overdue_at')->nullable();    // set once when first flagged overdue (events fire once)
            $table->timestampTz('paid_at')->nullable();
            $table->string('idempotency_key')->nullable();   // batch (charge set) key for safe retry
            $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));
            $table->integer('version')->default(0);
            $table->timestampsTz();

            $table->uniqueIndex('number')->where('number IS NOT NULL');
            $table->uniqueIndex('idempotency_key')->where('idempotency_key IS NOT NULL');
            $table->index(['account_id', 'state']);
        });
        Pg::currencyCheck(Pg::table('invoices'));
        Pg::enumCheck(Pg::table('invoices'), 'state', InvoiceState::class);

        Schema::create(Pg::table('invoice_lines'), function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('invoice_id')->constrained(Pg::table('invoices'))->cascadeOnDelete();
            $table->foreignUuid('charge_id')->nullable()->constrained(Pg::table('charges'))->nullOnDelete();
            $table->string('kind');
            $table->string('title')->nullable();             // line name (product + resource)
            $table->string('group')->nullable();             // invoice section heading (e.g. Domains, Usage)
            $table->text('description')->nullable();          // multi-line detail (period, usage breakdown)
            $table->decimal('quantity', 20, 6)->default(1);
            $table->string('unit')->nullable();              // quantity unit label (month, hours, GB)
            $table->bigInteger('unit_minor')->nullable();
            $table->decimal('unit_rate', 20, 8)->nullable();
            $table->bigInteger('amount_minor');
            $table->decimal('tax_rate', 6, 4)->default(0);
            $table->bigInteger('tax_minor')->default(0);
            $table->string('tax_label')->nullable();
            $table->char('currency', 3);
            $table->timestampTzRange('covers')->nullable();
            $table->uuid('dimension_id')->nullable();
            $table->integer('sort')->default(0);
            $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));

            $table->index(['invoice_id', 'sort']);
        });
        Pg::currencyCheck(Pg::table('invoice_lines'));
        Pg::enumCheck(Pg::table('invoice_lines'), 'kind', LineKind::class);

        Schema::create(Pg::table('credit_notes'), function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('invoice_id')->constrained(Pg::table('invoices'))->restrictOnDelete();
            $table->string('number')->nullable();
            $table->string('driver')->default('database');
            $table->string('external_id')->nullable();
            $table->string('state')->default(CreditState::Draft->value);
            $table->string('reason')->nullable();
            $table->bigInteger('amount_minor');
            $table->bigInteger('tax_minor')->default(0);
            $table->char('currency', 3);
            $table->timestampTz('issued_at')->nullable();
            $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));
            $table->timestampTz('created_at')->useCurrent();

            $table->uniqueIndex('number')->where('number IS NOT NULL');
        });
        Pg::currencyCheck(Pg::table('credit_notes'));
        Pg::enumCheck(Pg::table('credit_notes'), 'state', CreditState::class);

        Schema::create(Pg::table('payments'), function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('account_id')->constrained(Pg::table('billing_accounts'))->restrictOnDelete();
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('reference')->nullable()->unique();
            $table->timestampTz('received_at')->useCurrent();
            $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));
        });
        Pg::currencyCheck(Pg::table('payments'));
        Pg::check(Pg::table('payments'), 'meteric_payments_amount_pos', 'amount_minor > 0');

        Schema::create(Pg::table('payment_allocations'), function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('payment_id')->constrained(Pg::table('payments'))->cascadeOnDelete();
            $table->foreignUuid('invoice_id')->constrained(Pg::table('invoices'))->restrictOnDelete();
            $table->bigInteger('amount_minor');

            $table->unique(['payment_id', 'invoice_id']);
        });
        Pg::check(Pg::table('payment_allocations'), 'meteric_alloc_amount_pos', 'amount_minor > 0');
    }

    public function down(): void
    {
        Schema::dropIfExists(Pg::table('payment_allocations'));
        Schema::dropIfExists(Pg::table('payments'));
        Schema::dropIfExists(Pg::table('credit_notes'));
        Schema::dropIfExists(Pg::table('invoice_lines'));
        Schema::dropIfExists(Pg::table('invoices'));
    }
};
