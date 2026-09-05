# Accounts: moving records between them

A `BillingAccount` is the thing charges, subscriptions, invoices, orders and payments hang
off. `Meteric::transferAccount()` moves every one of them from one account to another.

```php
use Meteric\Facades\Meteric;

$moved = Meteric::transferAccount($from, $to);
// ['meteric_subscriptions' => 1, 'meteric_invoices' => 3, 'meteric_orders' => 1,
//  'meteric_charges' => 7, 'meteric_payments' => 2, 'meteric_ledger' => 0]
```

The case it exists for is a host app that has decided two accounts are one customer. Without
it the host app would have to write these tables itself, which is exactly what it must not do.

## What it moves

Six tables carry `account_id`: `subscriptions`, `invoices`, `orders`, `charges`, `payments`
and `ledger`. The first three also carry `customer_type`/`customer_id`, the customer morph
frozen on the row when it was written, and that moves too. **The morph is not redundant.** It
is what a host app reads to list one customer's invoices, so a row whose account moved and
whose morph did not would be listed under neither customer.

Everything else reaches an account through a parent that is in that list, and follows without
being touched: a credit note through its invoice, an allocation and a refund through their
payment, lines, items, addons, options, discounts and usage records through their invoice or
subscription.

## What it does not move

**Money.** No amount, no tax figure, no currency, no document number and no state is read or
written. An issued invoice keeps its number, its lines, its totals and its seal, and the
`meteric_invoices_immutable` trigger stays satisfied rather than being worked around. What
changes is who the records belong to, not what they say.

The `from` account row itself stays, empty. It is still the account its owner resolves to,
and deleting it is the caller's decision rather than a side effect of a transfer.

## What it refuses

`Meteric\Exceptions\AccountNotTransferable`, and nothing is written:

- the same account twice
- an account that has not been saved
- **two currencies.** Every amount on these rows is minor units of the account's currency, so
  putting them on an account that reads them as another currency restates every figure
  without touching one. Refused, never converted
- an account that still has **sub-accounts**. A payer with children is the root of a tree
  whose children keep pointing at it; moving its records would leave a consolidating account
  with children and no history, so the caller has to say what the tree should become first

## The event

`Meteric\Events\AccountTransferred` carries `from`, `to` and the same `moved` counts, so a
listener can reconcile without re-counting.
