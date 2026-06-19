# Invoicing

Invoicing is where Billify's core promise lives: a charge is the source of
truth, and an invoice is a document that bills a set of charges. The two are
decoupled so an outage in your accounting system never loses revenue.

## The charge-vs-invoice guarantee

A `Charge` accrues as `pending` the moment money is owed, a renewal, an
upgrade, a usage rollup, an addon. It has nothing to do with documents yet.

`invoicePending()` collects an account's pending charges in one currency, builds
a draft, and hands it to the bound invoice driver. Then:

- If the driver's `issue()` **succeeds**, the charges flip from `pending` to
  `invoiced` inside a transaction, attached to the new invoice.
- If `issue()` **throws**, accounting API down, network timeout, nothing flips.
  The charges stay `pending`. The exception is re-thrown so you see it, and the
  next run picks the same charges up.

```php
use Billify\Facades\Billify;

$invoice = Billify::invoicePending($account);
// null when nothing was pending; otherwise the issued Invoice.
```

The driver call is the failure boundary. A retried run reuses a deterministic
batch key, so retrying after a partial failure does not create a second invoice
for the same charges.

## Drivers

The invoice driver is swappable. The default `database` driver writes the
invoice and its lines to the `billify_*` tables. To send invoices to an external
system, bind a class implementing `Billify\Contracts\InvoiceDriver`:

```php
// config/billify.php
'invoice' => [
    'driver' => 'lexoffice',
    'drivers' => [
        'database'  => \Billify\Invoicing\Drivers\DatabaseInvoiceDriver::class,
        'lexoffice' => \App\Billing\LexofficeInvoiceDriver::class,
    ],
    'mirror_to_database' => true,
],
```

Throwing from `issue()` is the boundary that preserves pending charges, so a
remote driver that fails to reach its API should throw rather than swallow the
error. With `mirror_to_database` on, the canonical invoice is still written
locally even when a remote driver is primary. Reach the active driver directly
with `Billify::driver()`.

## Payments

Billify does not talk to gateways. When your gateway confirms money arrived, you
record it against the invoice:

```php
use Brick\Money\Money;

Billify::recordPayment($invoice, Money::of('49.98', 'EUR'), 'pi_123');
```

This creates a `Payment` and a `PaymentAllocation` and advances the invoice
state: `partially_paid` while the running total is below the invoice total,
`paid` once it reaches it. Read the position off the invoice:

```php
$invoice->total();        // Money
$invoice->outstanding();  // Money still owed
$invoice->isPaid();       // bool
$invoice->isOverdue();    // bool, issued, past due, not paid
```

## Consolidated billing

A payer account can bill its own pending charges plus all its child accounts'
charges onto a single invoice, a reseller or an organization with sub-accounts.

```php
$invoice = Billify::invoiceConsolidated($payer);
```

This collects pending charges across the payer's scope (itself and its
children, via `payerScopeIds()`) and issues one invoice, itemized per account.
The same guarantee applies: a driver failure leaves every charge `pending`.

Set the relationship by giving a child account a `parent_id`:

```php
use Billify\Models\BillingAccount;

BillingAccount::create([
    'owner_type' => $org->getMorphClass(),
    'owner_id' => $org->getKey(),
    'parent_id' => $payer->id,
    'currency' => 'EUR',
]);
```
