<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The TRUNCATE refusal, narrowed to a statement that removes something.
     *
     * `2026_09_10_100023_seal_the_issue_of_an_invoice` refused every `TRUNCATE`
     * on `invoices` and `invoice_lines`, empty or not. Truncating an empty table
     * destroys no document, and refusing one costs a fresh install its fixtures
     * and a reference-data seeder whose `CASCADE` happens to reach these tables
     * - `nnjeim/world` truncates `countries` that way, and any foreign key into
     * it carries the statement here.
     *
     * So the trigger reads the table and raises only where a row is there,
     * which is every case the refusal was written for. The statement holds an
     * `ACCESS EXCLUSIVE` lock, so nothing arrives between the read and the
     * refusal, and a row cannot be removed first: the row guard beside this one
     * refuses the delete.
     *
     * A separate migration rather than an edit to that one, because an
     * installation that has already run it would not run it again.
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
        CREATE OR REPLACE FUNCTION meteric_never_truncated() RETURNS trigger AS $$
        DECLARE holds boolean;
        BEGIN
          EXECUTE format('SELECT EXISTS (SELECT 1 FROM %I.%I)', TG_TABLE_SCHEMA, TG_TABLE_NAME) INTO holds;

          IF holds THEN
            RAISE EXCEPTION 'meteric: % holds issued documents and is never truncated', TG_TABLE_NAME;
          END IF;

          RETURN NULL;
        END;
        $$ LANGUAGE plpgsql;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
        CREATE OR REPLACE FUNCTION meteric_never_truncated() RETURNS trigger AS $$
        BEGIN
          RAISE EXCEPTION 'meteric: % holds issued documents and is never truncated', TG_TABLE_NAME;
        END;
        $$ LANGUAGE plpgsql;
        SQL);
    }
};
