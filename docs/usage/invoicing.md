# Invoicing

An invoice is a document made of line items. A line item can reference a `Charge`
(the record of money owed), or stand alone as a manual line. Meteric creates
charges and invoices for you (subscriptions and usage accrue charges; a billing
run invoices them), and you can create either by hand: a `Charge` directly, or an
invoice line by line.

## Creating invoices

Two ways to get an invoice: let the billing run make it, or build one yourself.

### Automatically (the billing run)

The scheduled `meteric:run` tick invoices an account's pending charges after
renewing its subscriptions, so recurring billing needs no manual call.

To bill a one-off this way, add a custom charge to the account. It sits `pending`,
and the account's next invoice (next run) includes it:

```php
use Brick\Money\Money;
use Meteric\Facades\Meteric;

Meteric::charge($account, Money::ofMinor(5000, 'EUR'), 'Setup fee', group: 'Services');
// pending; the account's next invoice bills it.
```

`charge(BillingAccount $account, Money $amount, string $title, ?string $group = null, ?string $description = null, LineKind $kind = LineKind::OneOff): Charge`
creates the charge in `pending`. Off-cycle, `Meteric::invoicePending($account)`
issues an account's pending charges immediately.

### Manually (build it now)

For a standalone invoice you control, open an empty draft, add lines, then finalize:

```php
$draft = Meteric::createInvoice($account);
$line  = Meteric::addLine($draft, 'Consulting', Money::ofMinor(50000, 'EUR'), 'October');
Meteric::addSubLine($line, 'Travel', Money::ofMinor(15000, 'EUR'));
$invoice = Meteric::finalizeInvoice($draft);
```

`createInvoice(BillingAccount $account, ?string $currency = null): Invoice` opens a
draft with no charges, lines, or totals. Each edit recomputes the totals. See
[Editing a draft](#editing-a-draft) for the line-method signatures.

To start from the account's pending charges but review or adjust before sending,
`Meteric::draftInvoice($account)` builds a draft from pending charges. Edit it,
then `finalizeInvoice`.

### Copy and re-issue

`copyInvoice` clones an invoice's header and lines, including the `parent_id`
sub-line hierarchy, into a fresh draft. Each cloned line keeps its `charge_id`, so
no charge is duplicated and no charge state changes:

```php
$copy = Meteric::copyInvoice($source);
Meteric::voidInvoice($source);
$fixed = Meteric::finalizeInvoice($copy);
```

That is the re-issue flow for a wrong document with right charges (a wrong billing
address, say). Because the copy's lines reference the same charges, voiding the
source leaves those charges `invoiced`: they still have a live line on the copy.

## Lifecycle

An invoice moves `draft` -> `open` -> `paid` or `void`. A draft is editable. An
open invoice is immutable, frozen by database triggers, so corrections go through a
[void](#void-or-credit-note) or a [credit note](#credit-notes-and-refunds), not an
in-place edit.

## Editing a draft

Add and remove lines on a draft directly. All three methods require a draft and
throw otherwise. They edit the lines in place, so they never rebuild from charges
and never wipe a manual line.

```php
use Brick\Money\Money;

$line = Meteric::addLine($draft, 'Consulting', Money::ofMinor(50000, 'EUR'), 'October');
Meteric::addSubLine($line, 'Travel', Money::ofMinor(15000, 'EUR'));
Meteric::removeLine($line);   // cascades its sub-lines
```

- `addLine(Invoice $invoice, string $title, Money $amount, ?string $description = null, ?string $group = null, LineKind $kind = LineKind::OneOff): InvoiceLine`
  adds a top-level line with no charge behind it. Tax resolves from the account's
  context.
- `addSubLine(InvoiceLine $parent, string $title, Money $amount, ?string $description = null, LineKind $kind = LineKind::Option): InvoiceLine`
  nests a child under an existing line. The child counts toward the totals on its
  own.
- `removeLine(InvoiceLine $line): void` deletes a line and cascades its sub-lines.
  If the line was a charge's last live line, the charge returns to `pending`.

Finalize the draft with:

```php
$invoice = Meteric::finalizeInvoice($draft);
```

`finalizeInvoice(Invoice $draft): Invoice` requires a draft. It sends the draft's
current lines through the driver, sets the due date from
`meteric.invoice.net_days`, flips the invoice to `open`, and fires `InvoiceIssued`.
`recordPayment` and `markOverdue` apply from here. A driver failure leaves the
draft untouched.

## Sub-lines

Each charge gets its own `InvoiceLine`. Within a product, the base charge becomes
the parent line and the options and addons nest under it through `parent_id`:

```php
$parent = $invoice->lines->whereNull('parent_id')->first();

foreach ($parent->children as $sub) {
    // $sub->parent_id === $parent->id
}
```

Every line carries its own `amount_minor` and its own per-line tax. A parent's
amount is its own line only; the children carry theirs. The invoice subtotal, tax,
and total sum every row, parent and child. Because tax resolves per line and not on
a summed net, a mixed-rate group can differ by a cent from a single summed-net
computation; the per-line sums are authoritative.

A charge with a null `line_group` becomes a standalone parent line with no
children. Every charge in a group flips to `invoiced` when its line is written.

A driver's `issue(InvoiceDraft $draft)` receives `$draft->charges`, a flat
collection of `Charge` rows. A product and its options and addons arrive as
separate charges, tied together by two fields:

- `line_group`: the owning subscription item id. Every charge a product produces
  (the base line, each option, each addon, proration, setup, usage) carries the
  same `line_group`. Account-level charges with no item have a null `line_group`.
- `kind`: a `LineKind`. `$charge->kind->isBaseLine()` returns true for `Recurring`,
  `Prorated`, `FullPeriod`, and `OneOff`, and false for `Option`, `Addon`, `Setup`,
  `Usage`, `Discount`, and `Credit`.

Group the charges by `line_group` to reconstruct a product, then pick the base line
as the parent:

```php
foreach ($draft->charges->groupBy('line_group') as $group) {
    $parent = $group->firstWhere(fn ($c) => $c->kind->isBaseLine()) ?? $group->first();
    $subItems = $group->reject(fn ($c) => $c === $parent);
}
```

## Drivers

The invoice driver is swappable. The default `database` driver writes the invoice
and its lines to the `meteric_*` tables. To send invoices to an external system,
bind a class implementing `Meteric\Contracts\InvoiceDriver`:

```php
// config/meteric.php
'invoice' => [
    'driver' => 'lexoffice',
    'drivers' => [
        'database'  => \Meteric\Invoicing\Drivers\DatabaseInvoiceDriver::class,
        'lexoffice' => \Meteric\Invoicing\Drivers\LexofficeInvoiceDriver::class,
    ],
],
```

Throwing from `issue()` is the boundary that preserves pending charges, so a remote
driver that fails to reach its API should throw rather than swallow the error. The
bundled `lexoffice` driver composes the database driver, so the canonical invoice is
always written locally even when the remote push fails. Reach the active driver with
`Meteric::driver()`.

The bundled `lexoffice` driver wraps the `database` driver: it persists the
canonical local invoice first, then finalizes the document in Lexware Office. The
local invoice is the source of truth, so if the Lexware Office call fails it
re-throws without rolling the local invoice back. See
[Lexware Office (lexoffice)](#lexware-office-lexoffice) below.

## Lexware Office (lexoffice)

To send finalized invoices and credit notes to Lexware Office, set the driver and
token:

```dotenv
METERIC_INVOICE_DRIVER=lexoffice
METERIC_LEXOFFICE_TOKEN=your-api-token
```

The config block:

```php
// config/meteric.php
'invoice' => [
    'lexoffice' => [
        'api_token' => env('METERIC_LEXOFFICE_TOKEN'),
        'base_url'  => env('METERIC_LEXOFFICE_BASE_URL', 'https://api.lexware.io'),
        'tax_type'  => 'net',   // line amounts are posted net
        'country'   => 'DE',
    ],
],
```

Production runs against `https://api.lexware.io`. Trial and sandbox keys only work
against the sandbox gateway at `https://api.lexware-sandbox.io`; generate them at
`app.lexware-sandbox.de/addons/public-api`.

The driver keeps the canonical invoice locally, then POSTs to Lexware Office and
stores the returned id and resource URI on `external_id` / `external_url`:

- `issue()` posts to `/v1/invoices?finalize=true`.
- `creditNote()` posts to `/v1/credit-notes?finalize=true`.

Lines map to lexoffice line items: the line title becomes `name`, the multi-line
description stays the description, `quantity` and `unit` (as `unitName`) carry over,
and amounts post net with a `taxRatePercentage` so lexoffice computes the gross.
Lexoffice has no native line nesting, so the sub-line hierarchy flattens: each
parent posts as a custom item, then its children follow as their own custom items
with an indented `- {title}` name, each carrying its own net and tax. The net of
every posted line sums to the invoice subtotal. A parent line `group` becomes a
`type:"text"` separator (a heading row), and the billed cycle posts as a
`serviceperiod` spanning the invoice with an inclusive end date.

## Payments

Meteric does not talk to gateways. When your gateway confirms money arrived, record
it against the invoice:

```php
use Brick\Money\Money;

Meteric::recordPayment($invoice, Money::of('49.98', 'EUR'), 'pi_123');
```

This creates a `Payment` and a `PaymentAllocation` and advances the invoice state:
`partially_paid` while the running total is below the invoice total, `paid` once it
reaches it. A full payment settles the charges the invoice billed. Read the position
off the invoice:

```php
$invoice->total();        // Money
$invoice->outstanding();  // Money still owed
$invoice->isPaid();       // bool
$invoice->isOverdue();    // bool, issued, past due, not paid
```

To reverse a payment, issue a [credit note](#credit-notes-and-refunds) and refund
through your gateway.

## Credit notes and refunds

Meteric does not move money. A credit note is the accounting reversal document. The
refund itself is your payment gateway's job, the same gateway-agnostic split as
payments: Meteric records the document, you move the money.

```php
use Brick\Money\Money;

// Reverse the full net of an invoice; VAT is mirrored automatically.
$note = Meteric::creditNote($invoice, Money::ofMinor($invoice->subtotal_minor, 'EUR'), 'Customer refund');
```

`creditNote(Invoice $invoice, Money $amount, ?string $reason = null): CreditNote`
takes the **net** amount to credit. The driver adds the invoice's tax rate on top so
the credit note reverses the same VAT the invoice charged, and fires a
`CreditNoteIssued` event. A credit note mirrors the invoice's tax rate: a net 10.00
EUR credit at 19% VAT comes to 11.90 EUR gross. The `CreditNote` model carries
`amount_minor` (net), `tax_minor` (mirrored), `currency`, `number`, `reason`, and
`state`.

With the [Lexware Office driver](#lexware-office-lexoffice), `creditNote()` also
POSTs a real credit-note document to lexoffice (`POST /v1/credit-notes?finalize=true`)
and stores its `external_id`.

### Void or credit note

`Meteric::voidInvoice($invoice)` cancels an invoice issued in error, before any money
moves. It works only on an unpaid invoice and refuses once any payment exists;
correct a paid or finalized invoice with a credit note instead.

```php
Meteric::voidInvoice($invoice);
```

Voiding returns each charge the invoice billed to `pending`, so the next
`invoicePending` re-bills it. A charge stays put if it still has a line on another
non-void invoice (a re-issued copy), or if it is settled or soft-deleted. To re-bill
onto a corrected document, copy the invoice first so the charges keep a live line,
then void the original. See [Copy and re-issue](#copy-and-re-issue).

Voiding routes through the driver, so the Lexware Office driver voids a draft that
never reached the API and refuses a finalized one (use a credit note).

## Consolidated billing

A payer account can bill its own pending charges plus all its child accounts' charges
onto a single invoice, a reseller or an organization with sub-accounts:

```php
$invoice = Meteric::invoiceConsolidated($payer);
```

This collects pending charges across the payer's scope (itself and its children, via
`payerScopeIds()`) and issues one invoice, itemized per account. A driver failure
leaves every charge `pending`.

Set the relationship by giving a child account a `parent_id`:

```php
use Meteric\Models\BillingAccount;

BillingAccount::create([
    'owner_type' => $org->getMorphClass(),
    'owner_id' => $org->getKey(),
    'parent_id' => $payer->id,
    'currency' => 'EUR',
]);
```

## How charges and invoices relate

A `Charge` accrues as `pending` the moment money is owed: a renewal, an upgrade, a
usage rollup, an addon. The link between a charge and an invoice is the line,
`invoice_lines.charge_id`. A charge moves through four states, maintained by the line
that references it:

- `pending`: owed, not yet on any live invoice. The billable pool.
- `invoiced`: a line references it on a non-void invoice.
- `settled`: that invoice is paid in full.
- `void`: discarded. A charge can also be soft-deleted to drop it entirely.

Two facts follow from the charge being the source of truth:

- The charge stays `pending` if the driver throws. An accounting outage loses no
  revenue; the next run reuses a deterministic batch key and retries the same
  charges, so a partial failure does not create a second invoice.
- An invoice total never goes negative. Credits ride as itemized negative lines,
  each carrying the product name of what it credits, offsetting the positive charges
  down to zero and no further. If pending credits outweigh the charges,
  `invoicePending` issues nothing and returns `null`; the credit lines stay `pending`
  and reduce a later invoice. Money back to a customer is a credit note, not a
  negative invoice.
