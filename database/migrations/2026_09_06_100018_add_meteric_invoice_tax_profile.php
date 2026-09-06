<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Meteric\Support\Pg;
use Tpetry\PostgresqlEnhanced\Schema\Blueprint;
use Tpetry\PostgresqlEnhanced\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The tax profile the invoice's lines were priced under, kept on the
     * invoice rather than read back off the account.
     *
     * An account's profile is current: it follows the customer as they move
     * country, register for VAT or stop being a business. An issued invoice is
     * historical, so deriving anything from the account rewrites documents that
     * were already sent. The snapshot answers what was true then; the account
     * answers what is true now.
     *
     * Null on an invoice issued before the column existed. It stays null: a
     * profile assembled today would state a country nobody recorded, so a
     * caller reading it has to decide what to do with an unanswered one.
     */
    public function up(): void
    {
        Schema::table(Pg::table('invoices'), function (Blueprint $table) {
            $table->jsonb('tax_profile')->nullable();
        });

        // The seal is the same one that holds the totals: draft is editable,
        // anything else is history. Repriced with every line write while the
        // invoice is a draft, frozen the moment it leaves one.
        DB::unprepared(<<<'SQL'
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
            IF NEW.tax_profile IS DISTINCT FROM OLD.tax_profile THEN
              RAISE EXCEPTION 'meteric: issued invoice % tax profile is immutable', OLD.id;
            END IF;
          END IF;
          RETURN NEW;
        END;
        $$ LANGUAGE plpgsql;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
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
        SQL);

        Schema::table(Pg::table('invoices'), function (Blueprint $table) {
            $table->dropColumn('tax_profile');
        });
    }
};
