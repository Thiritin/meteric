<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Meteric\Support\Pg;

return new class extends Migration
{
    /**
     * An invoice money has been paid against is not voided.
     *
     * `voidInvoice()` has always refused one: a document cancelled after it was
     * settled leaves a payment allocated to a document that officially never
     * existed, and the instrument for a paid invoice that turns out to be wrong
     * is a credit note, which states the reversal instead of hiding the
     * original. That rule lived only in the manager, so one `UPDATE` moved a
     * `paid` invoice to `void` and the seal beside it - which freezes the
     * currency, the totals and the tax profile of every issued document - said
     * nothing.
     *
     * A migration, a seeder, a console session and a future caller do not go
     * through the manager, and those are the four the seal exists for. The rule
     * is the manager's own, unchanged: `paid_minor` above zero, or any payment
     * allocated to the invoice, and the state cannot become `void`.
     *
     * Everything else about the state stays open on purpose. `open`,
     * `partially_paid`, `paid` and `uncollectible` are a lifecycle the caller
     * drives, and freezing transitions here would put a state machine in a
     * trigger where the manager already holds one.
     */
    public function up(): void
    {
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
            IF NEW.tax_profile IS DISTINCT FROM OLD.tax_profile THEN
              RAISE EXCEPTION 'meteric: issued invoice % tax profile is immutable', OLD.id;
            END IF;
          END IF;
          RETURN NEW;
        END;
        $$ LANGUAGE plpgsql;
        SQL);
    }
};
