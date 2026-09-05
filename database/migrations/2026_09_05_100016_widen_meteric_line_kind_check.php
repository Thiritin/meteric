<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Meteric\Enums\LineKind;
use Meteric\Support\Pg;

/**
 * `LineKind` gained `text`, and the check constraint on the two tables that
 * carry a kind is written from the enum **at migration time**.
 *
 * So a database created after the case was added already allows it and one
 * migrated before does not, which is the worst shape a constraint can be in: it
 * passes on a fresh test database and rejects the write in every deployment
 * that has been running. Both tables are widened here so the two agree again.
 */
return new class extends Migration
{
    private const TABLES = ['invoice_lines', 'charges'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            $this->rewrite($table, LineKind::cases());
        }
    }

    public function down(): void
    {
        $before = array_filter(LineKind::cases(), fn (LineKind $kind) => $kind !== LineKind::Text);

        foreach (self::TABLES as $table) {
            $this->rewrite($table, $before);
        }
    }

    /**
     * @param  array<int, LineKind>  $kinds
     */
    private function rewrite(string $table, array $kinds): void
    {
        $name = Pg::table($table);
        $list = "'".implode("','", array_map(fn (LineKind $kind) => $kind->value, $kinds))."'";

        DB::statement("ALTER TABLE {$name} DROP CONSTRAINT IF EXISTS {$name}_kind_check");
        DB::statement("ALTER TABLE {$name} ADD CONSTRAINT {$name}_kind_check CHECK (kind IN ({$list}))");
    }
};
