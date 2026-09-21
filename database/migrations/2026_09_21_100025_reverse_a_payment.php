<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Meteric\Support\Pg;

return new class extends Migration
{
    /**
     * A payment that was taken back is a second allocation row, negative, not
     * an edit of the first.
     *
     * The original allocation is the record that the money was applied, and it
     * stays true of the moment it describes. What changed afterwards is its own
     * fact with its own date and its own ground, so the table holds both and
     * `sum(amount_minor)` over an invoice is what is allocated to it right now,
     * without a reader having to know which rows to skip.
     *
     * Three constraints move with the column. The positive CHECK becomes a sign
     * that follows the kind, so an original can only be positive and a reversal
     * can only be negative. The unique pair becomes partial, because one
     * payment may only be allocated to one invoice once but may be taken back
     * from it more than once. `recorded_at` is null on every row written before
     * this migration and is not invented for them.
     */
    public function up(): void
    {
        $table = Pg::table('payment_allocations');

        Schema::table($table, function (Blueprint $blueprint) use ($table): void {
            $blueprint->foreignUuid('reverses_allocation_id')->nullable()->constrained($table)->restrictOnDelete();
            $blueprint->text('reason')->nullable();
            $blueprint->timestampTz('recorded_at')->nullable()->default(DB::raw('now()'));
        });

        DB::statement("ALTER TABLE {$table} DROP CONSTRAINT meteric_alloc_amount_pos");
        Pg::check($table, 'meteric_alloc_sign_matches_kind', <<<'SQL'
        (reverses_allocation_id IS NULL AND amount_minor > 0)
        OR (reverses_allocation_id IS NOT NULL AND amount_minor < 0)
        SQL);

        DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$table}_payment_id_invoice_id_unique");
        DB::statement(
            "CREATE UNIQUE INDEX meteric_alloc_one_per_invoice ON {$table} (payment_id, invoice_id) WHERE reverses_allocation_id IS NULL"
        );
    }

    public function down(): void
    {
        $table = Pg::table('payment_allocations');

        DB::statement("DELETE FROM {$table} WHERE reverses_allocation_id IS NOT NULL");
        DB::statement('DROP INDEX IF EXISTS meteric_alloc_one_per_invoice');
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_payment_id_invoice_id_unique UNIQUE (payment_id, invoice_id)");
        DB::statement("ALTER TABLE {$table} DROP CONSTRAINT meteric_alloc_sign_matches_kind");
        Pg::check($table, 'meteric_alloc_amount_pos', 'amount_minor > 0');

        Schema::table($table, function (Blueprint $blueprint): void {
            $blueprint->dropConstrainedForeignId('reverses_allocation_id');
            $blueprint->dropColumn(['reason', 'recorded_at']);
        });
    }
};
