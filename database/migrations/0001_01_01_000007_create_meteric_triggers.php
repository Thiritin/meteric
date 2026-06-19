<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
        -- Issued invoices: block deletes; freeze financial columns.
        CREATE OR REPLACE FUNCTION meteric_invoice_immutable() RETURNS trigger AS $$
        BEGIN
          IF OLD.state <> 'draft' THEN
            IF TG_OP = 'DELETE' THEN
              RAISE EXCEPTION 'meteric: issued invoice % cannot be deleted', OLD.id;
            END IF;
            IF NEW.currency <> OLD.currency OR NEW.subtotal_minor <> OLD.subtotal_minor
               OR NEW.total_minor <> OLD.total_minor OR NEW.tax_minor <> OLD.tax_minor THEN
              RAISE EXCEPTION 'meteric: issued invoice % financials are immutable', OLD.id;
            END IF;
          END IF;
          RETURN NEW;
        END;
        $$ LANGUAGE plpgsql;
        CREATE TRIGGER meteric_invoices_immutable BEFORE UPDATE OR DELETE ON meteric_invoices
          FOR EACH ROW EXECUTE FUNCTION meteric_invoice_immutable();

        -- Lines of a non-draft invoice are frozen entirely.
        CREATE OR REPLACE FUNCTION meteric_line_immutable() RETURNS trigger AS $$
        DECLARE st text;
        BEGIN
          SELECT state INTO st FROM meteric_invoices WHERE id = COALESCE(NEW.invoice_id, OLD.invoice_id);
          IF st IS NOT NULL AND st <> 'draft' THEN
            RAISE EXCEPTION 'meteric: lines of issued invoice are immutable';
          END IF;
          RETURN COALESCE(NEW, OLD);
        END;
        $$ LANGUAGE plpgsql;
        CREATE TRIGGER meteric_lines_immutable BEFORE INSERT OR UPDATE OR DELETE ON meteric_invoice_lines
          FOR EACH ROW EXECUTE FUNCTION meteric_line_immutable();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
        DROP TRIGGER IF EXISTS meteric_lines_immutable ON meteric_invoice_lines;
        DROP TRIGGER IF EXISTS meteric_invoices_immutable ON meteric_invoices;
        DROP FUNCTION IF EXISTS meteric_line_immutable();
        DROP FUNCTION IF EXISTS meteric_invoice_immutable();
        SQL);
    }
};
