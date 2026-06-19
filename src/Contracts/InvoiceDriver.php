<?php

declare(strict_types=1);

namespace Meteric\Contracts;

use Meteric\Invoicing\CreditNoteDraft;
use Meteric\Invoicing\InvoiceDraft;
use Meteric\Invoicing\IssuedCreditNote;
use Meteric\Invoicing\IssuedInvoice;

/**
 * Emits invoices to a persistence/accounting target. THE failure boundary:
 * if issue() throws, the caller leaves all charges `pending` and writes no
 * `invoiced` state — so an outage never loses revenue (DESIGN §2.5).
 *
 * Implementations: DatabaseInvoiceDriver (ships), LexofficeInvoiceDriver (app).
 */
interface InvoiceDriver
{
    public function issue(InvoiceDraft $draft): IssuedInvoice;

    public function void(IssuedInvoice $invoice): void;

    public function creditNote(IssuedInvoice $invoice, CreditNoteDraft $draft): IssuedCreditNote;
}
