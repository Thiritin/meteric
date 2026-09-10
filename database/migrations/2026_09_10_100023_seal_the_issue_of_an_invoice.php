<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Meteric\Support\Pg;

return new class extends Migration
{
    /**
     * An issued invoice stays issued, and states the day it was issued.
     *
     * The seal already refuses to delete a document that has left `draft`, to
     * move its money or its tax profile, and to void one that has been paid.
     * Two facts it said nothing about, both `UPDATE 1` in one statement:
     *
     * - **`state` back to `draft`.** No method un-issues a document. A draft is
     *   what has not been issued yet: it has no number, its lines are editable,
     *   and `finalizeInvoice` is the one way out of it. Putting an issued
     *   invoice back into that state is not a lifecycle transition, it is an
     *   issued document being unwritten, and it takes the invoice out of every
     *   figure computed over issued documents while its number and its issue
     *   date stay on the row.
     * - **`issued_at` moved.** `finalizeInvoice` writes it once and nothing
     *   reads it again to change it. It is the tax point: what it says decides
     *   which period the document belongs to, so moving it reassigns a supply
     *   to another period while every annual total stays exactly the same. That
     *   is the one change to an issued document that is invisible in aggregate,
     *   which is why the row is the place to refuse it.
     *
     * Both are rules the manager already holds by having no path that breaks
     * them, and a migration, a seeder, a console session and a future caller do
     * not go through the manager.
     *
     * **The collection lifecycle stays the caller's, unchanged.** `open`,
     * `partially_paid`, `paid` and `uncollectible` move in both directions on
     * purpose: a payment that is reversed or charged back lowers `paid_minor`
     * and returns the document to `open`, which is ordinary and honest work.
     * Freezing that would put a state machine in a trigger where the manager
     * already keeps one, and the trigger would then be the thing to work around
     * rather than the thing that holds.
     *
     * `TRUNCATE` is refused on both tables while this is here. Every guard on
     * them is `FOR EACH ROW`, and a row-level trigger does not fire on a
     * `TRUNCATE`: Postgres empties the table without visiting the rows, so the
     * branch written to refuse a delete never runs and one statement removes
     * every issued document. Statement-level triggers are the answer Postgres
     * gives, and `REVOKE TRUNCATE` from the application role is the other half.
     *
     * An existing installation runs this and nothing else. No row is read,
     * changed or removed.
     */
    public function up(): void
    {
        $invoices = Pg::table('invoices');
        $lines = Pg::table('invoice_lines');
        $allocations = Pg::table('payment_allocations');

        DB::unprepared(<<<SQL
        CREATE OR REPLACE FUNCTION meteric_invoice_immutable() RETURNS trigger AS \$\$
        BEGIN
          IF OLD.state <> 'draft' THEN
            IF TG_OP = 'DELETE' THEN
              RAISE EXCEPTION 'meteric: issued invoice % cannot be deleted', OLD.id;
            END IF;
            IF NEW.currency <> OLD.currency OR NEW.subtotal_minor <> OLD.subtotal_minor
               OR NEW.total_minor <> OLD.total_minor OR NEW.tax_minor <> OLD.tax_minor THEN
              RAISE EXCEPTION 'meteric: issued invoice % financials are immutable', OLD.id;
            END IF;
            IF NEW.tax_profile IS DISTINCT FROM OLD.tax_profile THEN
              RAISE EXCEPTION 'meteric: issued invoice % tax profile is immutable', OLD.id;
            END IF;
            IF NEW.state = 'draft' THEN
              RAISE EXCEPTION 'meteric: invoice % has been issued and does not become a draft again', OLD.id;
            END IF;
            IF NEW.issued_at IS DISTINCT FROM OLD.issued_at THEN
              RAISE EXCEPTION 'meteric: issued invoice % states the day it was issued and that does not move', OLD.id;
            END IF;
            IF NEW.state = 'void' AND OLD.state <> 'void'
               AND (OLD.paid_minor > 0
                    OR EXISTS (SELECT 1 FROM {$allocations} a WHERE a.invoice_id = OLD.id)) THEN
              RAISE EXCEPTION 'meteric: invoice % has payments and is corrected by a credit note, not voided', OLD.id;
            END IF;
          END IF;
          RETURN NEW;
        END;
        \$\$ LANGUAGE plpgsql;

        CREATE OR REPLACE FUNCTION meteric_never_truncated() RETURNS trigger AS \$\$
        BEGIN
          RAISE EXCEPTION 'meteric: % holds issued documents and is never truncated', TG_TABLE_NAME;
        END;
        \$\$ LANGUAGE plpgsql;

        CREATE TRIGGER meteric_invoices_never_truncated BEFORE TRUNCATE ON {$invoices}
          FOR EACH STATEMENT EXECUTE FUNCTION meteric_never_truncated();

        CREATE TRIGGER meteric_lines_never_truncated BEFORE TRUNCATE ON {$lines}
          FOR EACH STATEMENT EXECUTE FUNCTION meteric_never_truncated();
        SQL);
    }

    public function down(): void
    {
        $invoices = Pg::table('invoices');
        $lines = Pg::table('invoice_lines');
        $allocations = Pg::table('payment_allocations');

        DB::unprepared(<<<SQL
        DROP TRIGGER IF EXISTS meteric_lines_never_truncated ON {$lines};
        DROP TRIGGER IF EXISTS meteric_invoices_never_truncated ON {$invoices};
        DROP FUNCTION IF EXISTS meteric_never_truncated();

        CREATE OR REPLACE FUNCTION meteric_invoice_immutable() RETURNS trigger AS \$\$
        BEGIN
          IF OLD.state <> 'draft' THEN
            IF TG_OP = 'DELETE' THEN
              RAISE EXCEPTION 'meteric: issued invoice % cannot be deleted', OLD.id;
            END IF;
            IF NEW.currency <> OLD.currency OR NEW.subtotal_minor <> OLD.subtotal_minor
               OR NEW.total_minor <> OLD.total_minor OR NEW.tax_minor <> OLD.tax_minor THEN
              RAISE EXCEPTION 'meteric: issued invoice % financials are immutable', OLD.id;
            END IF;
            IF NEW.tax_profile IS DISTINCT FROM OLD.tax_profile THEN
              RAISE EXCEPTION 'meteric: issued invoice % tax profile is immutable', OLD.id;
            END IF;
            IF NEW.state = 'void' AND OLD.state <> 'void'
               AND (OLD.paid_minor > 0
                    OR EXISTS (SELECT 1 FROM {$allocations} a WHERE a.invoice_id = OLD.id)) THEN
              RAISE EXCEPTION 'meteric: invoice % has payments and is corrected by a credit note, not voided', OLD.id;
            END IF;
          END IF;
          RETURN NEW;
        END;
        \$\$ LANGUAGE plpgsql;
        SQL);
    }
};
