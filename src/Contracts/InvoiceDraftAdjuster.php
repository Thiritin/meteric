<?php

declare(strict_types=1);

namespace Meteric\Contracts;

use Illuminate\Support\Collection;
use Meteric\Models\BillingAccount;
use Meteric\Models\Charge;

/**
 * The last word on what goes on an invoice, before the driver issues it.
 *
 * An application often has a claim of its own to put on a document the engine
 * raises: a goodwill credit, a rebate, a rounding line, a levy. Until now the
 * only place to add one was a draft the application had opened itself, which
 * left every invoice the scheduled billing run raises out of reach - the run
 * calls invoicePending from inside the package and never returns a draft to
 * anybody. This is that window.
 *
 * **It returns charges rather than editing lines.** A charge is the unit the
 * engine bills, prices and taxes; a line is what a charge became. Returning
 * charges means the addition is composed, taxed and grouped exactly like every
 * other one, it counts toward the invoice's own totals and idempotency key, and
 * it survives a void the way the rest do - the charge goes back to pending and
 * is billed again.
 *
 * **It runs inside the caller's transaction**, so a driver that refuses the
 * document takes the adjustment back with it. Whatever the adjuster wrote about
 * its own state is rolled back too, which is what lets it draw down a balance
 * here without having to undo the draw by hand.
 *
 * Every charge returned must be pending, on this account, and in this currency:
 * an invoice is one account's claim in one currency, and a charge that is none
 * of those is refused rather than quietly billed to somebody.
 *
 * Bind an implementation over the no-op default:
 *
 *     $this->app->singleton(InvoiceDraftAdjuster::class, MyAdjuster::class);
 */
interface InvoiceDraftAdjuster
{
    /**
     * @param  Collection<int, Charge>  $charges  what is about to be billed
     * @return iterable<Charge> further pending charges to bill on the same document
     */
    public function adjust(BillingAccount $account, string $currency, Collection $charges): iterable;
}
