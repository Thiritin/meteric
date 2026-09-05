<?php

declare(strict_types=1);

namespace Meteric\Accounts;

use Illuminate\Support\Facades\DB;
use Meteric\Events\AccountTransferred;
use Meteric\Exceptions\AccountNotTransferable;
use Meteric\Models\BillingAccount;
use Meteric\Models\Charge;
use Meteric\Models\Invoice;
use Meteric\Models\Order;
use Meteric\Models\Payment;
use Meteric\Models\Subscription;
use Meteric\Support\Models;
use Meteric\Support\Pg;

/**
 * Every billing record on one account, moved to another.
 *
 * The case this exists for is one person holding two accounts: the host app has
 * decided they are one customer, and the billing history has to end up on the
 * account that survives. Without this the host app would have to write these
 * tables itself, which is the one thing it must not do.
 *
 * **This moves an ownership link and nothing else.** No amount, no tax figure,
 * no currency, no document number and no state is read or written here, so an
 * issued invoice keeps its number, its lines, its totals and its seal, and the
 * `meteric_invoices_immutable` trigger stays satisfied rather than worked
 * around. Money does not move; who the records belong to does.
 *
 * Six tables carry `account_id` and three of those also freeze the customer
 * morph at issue. Everything else reaches an account through a parent that is in
 * this list: a credit note through its invoice, an allocation and a refund
 * through their payment, lines, items, addons, options, discounts and usage
 * records through their invoice or subscription. Those follow without being
 * touched, which is why they are absent rather than forgotten.
 */
final class AccountTransfer
{
    /**
     * Tables keyed by the model that owns them, and whether the row also carries
     * the customer morph frozen on it at issue.
     *
     * The morph is not redundant with `account_id`: it is what a host app reads
     * to list one customer's invoices, so a row whose account moved and whose
     * morph did not would be listed under neither customer.
     *
     * @var array<class-string, bool>
     */
    private const MOVES = [
        Subscription::class => true,
        Invoice::class => true,
        Order::class => true,
        Charge::class => false,
        Payment::class => false,
    ];

    /**
     * @return array<string, int> rows moved, by table, zeros included
     *
     * @throws AccountNotTransferable
     */
    public function move(BillingAccount $from, BillingAccount $to): array
    {
        $this->refuseImpossible($from, $to);

        $moved = DB::transaction(function () use ($from, $to): array {
            $moved = [];

            foreach (self::MOVES as $model => $carriesMorph) {
                $query = Models::query($model)->where('account_id', $from->id);

                $values = ['account_id' => $to->id];

                if ($carriesMorph) {
                    $values['customer_type'] = $to->owner_type;
                    $values['customer_id'] = $to->owner_id;
                }

                $moved[$query->getModel()->getTable()] = $query->update($values);
            }

            // The ledger has no model of its own. It is double entry - a row is
            // a debit or a credit and never a running balance - so a row reads
            // the same on either account and moves without arithmetic.
            $ledger = Pg::table('ledger');

            $moved[$ledger] = DB::table($ledger)->where('account_id', $from->id)->update(['account_id' => $to->id]);

            return $moved;
        });

        AccountTransferred::dispatch($from, $to, $moved);

        return $moved;
    }

    /**
     * @throws AccountNotTransferable
     */
    private function refuseImpossible(BillingAccount $from, BillingAccount $to): void
    {
        if (! $from->exists || ! $to->exists) {
            throw AccountNotTransferable::notPersisted();
        }

        if ($from->id === $to->id) {
            throw AccountNotTransferable::sameAccount();
        }

        // Refused, never converted. Every amount on these rows is minor units of
        // the account's currency, so putting them on an account that reads them
        // as another currency restates every figure without touching one.
        if ((string) $from->currency !== (string) $to->currency) {
            throw AccountNotTransferable::currencyMismatch((string) $from->currency, (string) $to->currency);
        }

        // A payer with sub-accounts is the root of a tree whose children keep
        // pointing at it. Moving its records would leave a consolidating account
        // with children and no history, so the caller has to say what the tree
        // should become first.
        if ($from->children()->exists()) {
            throw AccountNotTransferable::hasChildren();
        }
    }
}
