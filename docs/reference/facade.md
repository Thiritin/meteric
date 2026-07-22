# Meteric facade

`Meteric\Facades\Meteric` is the entry point. It resolves `Meteric\Meteric` from
the container. Every method below is a static call on the facade.

```php
use Meteric\Facades\Meteric;
```

## Subscriptions

#### `subscribe(?Model $customer = null): SubscriptionBuilder`

Start building a subscription. Pass the billable customer model, or omit it and
set the account on the builder. End the chain with `->create()`, or with
`->checkout()` to create the subscription and immediately invoice the first
cycle. See [Subscriptions](/usage/subscriptions).

#### `renew(Subscription $sub, ?CarbonImmutable $at = null): array`

Accrue the next cycle for every due item, rolling forward through elapsed
periods. Idempotent. Returns the created charges.

#### `changePlan(SubscriptionItem $item, Price $newPrice, ?DowngradePolicy $downgrade = null, ?UpgradePolicy $upgrade = null, ?CarbonImmutable $at = null): SubscriptionItem`

Switch an item's plan. Direction is detected from the price. `$upgrade` picks the
upgrade policy, `$downgrade` the downgrade policy. See
[Plan changes](/usage/plan-changes).

#### `cancel(Subscription $sub, string|CarbonImmutable $at = 'period_end', ?CarbonImmutable $when = null, array $meta = []): Subscription`

Cancel at `'period_end'` (default), `'now'`, or a future term boundary. No
automatic refund. `$meta` stores cancellation data (a reason, a survey answer) on
the subscription metadata under `cancellation`. See
[Subscriptions](/usage/subscriptions#cancel).

#### `cancellationOptions(Subscription $sub, int $count = 3): array`

The next `$count` term boundaries that still satisfy the product's notice window,
as a `list<CarbonImmutable>`. Render these as a "cancel at end of period N" choice.

#### `processDueCancellations(?CarbonImmutable $at = null): int`

Enact scheduled cancellations whose boundary has passed: cancel the subscription
and fire `SubscriptionCanceled`. Returns the count. `meteric:run` calls this, so
you rarely call it directly.

#### `pause(Subscription $sub): Subscription`

Suspend billing (state → `paused`). `renew()` accrues nothing while paused.

#### `resume(Subscription $sub, ?CarbonImmutable $at = null): Subscription`

Resume billing (state → `active`) from `$at`, defaulting to now.

## Orders

#### `createOrder(?Model $customer = null): OrderBuilder`

Open a persisted, immutable order. Build the cart with `add()` / `addon()` /
`option()`, end with `->create()` to store a pending order, then pay or confirm
it later. No subscription, charge, or invoice exists until the order is paid. See
[Orders](/usage/orders).

#### `payOrder(Order $order, Money $amount, ?string $ref = null): Order`

Pay an order in full and materialize its subscription and paid invoice.

#### `confirmOrder(Order $order): Order`

Convert a zero-total order with no payment, e.g. a fully trialed signup.

#### `cancelOrder(Order $order): Order`

Cancel a pending order. No-op once terminal.

#### `expireOrders(?CarbonImmutable $at = null): int`

Expire pending orders past their expiry. Returns the count. `meteric:run` calls
this, so you rarely call it directly.

## Items: addons, options, quantity

#### `addAddon(SubscriptionItem $item, Price $price, ?string $group = null, float $qty = 1, ?CarbonImmutable $at = null): Addon`

Book a prorated addon. Members of the same `group` are swapped (the old one is
credited out).

#### `removeAddon(Addon $addon, ?CarbonImmutable $at = null): void`

Remove an addon mid-cycle with a prorated credit for the unused portion.

#### `setOption(SubscriptionItem $item, string $key, string $value, string $type, ?Price $price = null, float $qty = 1, ?CarbonImmutable $at = null, ?float $min = null, ?float $max = null, ?string $label = null): ItemOption`

Set a configurable option (slots, OS, toggle). Prorates the price delta when a
`price` is given; the option then recurs every renewal. `$min`/`$max` bound a
quantity and throw `InvalidArgumentException` when violated. `$value` is the raw
value the provisioning system reads (e.g. `1024`); `$label` is the display value
(e.g. `1 GB RAM`). Both snapshot onto the item, so deleting the catalog option
later does not change the selection.

#### `chooseOption(SubscriptionItem $item, ProductOptionValue $value, float $qty = 1, ?CarbonImmutable $at = null): ItemOption`

Apply a declared catalog option value. Reads the key, type, bounds, price, raw
value, and display label off the `ProductOptionValue`, then calls `setOption`.

#### `setQuantity(SubscriptionItem $item, float $qty, ?CarbonImmutable $at = null): SubscriptionItem`

Change an item's base quantity, prorating the difference.

## Usage

#### `billingCycle(SubscriptionItem $item): ?Period`

The current billing cycle window for an item. Query your usage API for this
range, then report the result with `recordUsage`.

#### `recordUsage(SubscriptionItem $item, string $dimension, float $quantity, ?CarbonImmutable $occurredAt = null, ?string $key = null): UsageRecord`

Report metered usage for a dimension. Idempotent on `key`.

#### `rollupUsage(SubscriptionItem $item, Period $period): array`

Roll up an item's usage window into in-arrears charges. Returns the created
charges. See [Usage billing](/usage/usage-billing).

## Quoting

#### `quote(): QuoteBuilder`

Start a read-only quote for checkout rendering. Nothing is persisted. See
[Quotes and checkout](/usage/quotes-and-checkout).

## Invoicing and payments

#### `charge(BillingAccount $account, Money $amount, string $title, ?string $group = null, ?string $description = null, LineKind $kind = LineKind::OneOff): Charge`

Add a one-off custom charge to an account; it accrues as `pending` and the next
billing run bills it. For a standalone document now, use `createInvoice`.

#### `invoicePending(BillingAccount $account, ?string $currency = null): ?Invoice`

Collect an account's pending charges in one currency and issue them via the
bound driver. Returns the invoice, or `null` when nothing is pending. Currency
defaults to the account's.

#### `invoiceAllPending(BillingAccount $account): array`

Invoice every currency that has pending charges for the account, not just the
account's default. A subscription or usage dimension can carry its own currency,
so billing only the default would strand the rest as permanently pending.
Returns the issued invoices, one per currency, as a `list<Invoice>`.

#### `invoiceConsolidated(BillingAccount $payer, ?string $currency = null): ?Invoice`

Bill the payer's own and all child accounts' pending charges onto a single
invoice, itemized per account.

#### `recordPayment(Invoice $invoice, Money $amount, ?string $reference = null): Payment`

Record an inbound payment against an invoice and advance its state.

#### `creditNote(Invoice $invoice, Money $amount, ?string $reason = null): CreditNote`

Issue a credit note reversing `$amount` (net) of an invoice. The driver mirrors
the invoice's tax on top and fires `CreditNoteIssued`. Meteric does not refund;
your gateway does. See [Credit notes and refunds](/usage/invoicing#credit-notes-and-refunds).

#### `voidInvoice(Invoice $invoice): Invoice`

Void an unpaid invoice. Refuses once any payment exists; correct a paid or
finalized invoice with a credit note instead. Returns each referenced charge to
`pending` unless it still has a line on another non-void invoice, or is settled
or soft-deleted.

#### `draftInvoice(BillingAccount $account, ?string $currency = null): Invoice`

Open an editable draft from the account's pending charges. Builds the lines and
flips each charge to `invoiced`. No number, due date, or `InvoiceIssued`.

#### `createInvoice(BillingAccount $account, ?string $currency = null): Invoice`

Open an empty editable draft with no charges. Build it with `addLine` /
`addSubLine`.

#### `addLine(Invoice $invoice, string $title, Money $amount, ?string $description = null, ?string $group = null, LineKind $kind = LineKind::OneOff): InvoiceLine`

Add a top-level line (no charge) to a draft. Recomputes totals. Throws on a
non-draft.

#### `addSubLine(InvoiceLine $parent, string $title, Money $amount, ?string $description = null, LineKind $kind = LineKind::Option): InvoiceLine`

Add a sub-line nested under `$parent` on a draft. Recomputes totals.

#### `removeLine(InvoiceLine $line): void`

Remove a line from a draft (cascades its sub-lines). Returns the charge to
`pending` when the removed line was its last live line.

#### `copyInvoice(Invoice $source): Invoice`

Clone an invoice's header and lines (with the `parent_id` hierarchy) into a fresh
draft, keeping each line's `charge_id`. No charge is duplicated.

#### `finalizeInvoice(Invoice $draft): Invoice`

Send a draft's current lines through the driver, set the due date, flip to
`open`, and fire `InvoiceIssued`. Throws on a non-draft.

#### `markOverdue(?CarbonImmutable $at = null): int`

Mark invoices past their due date as `past_due` and fire `InvoiceOverdue`.
Returns the count. `meteric:run` calls this, so you rarely call it directly.

#### `driver(): InvoiceDriver`

The bound invoice driver instance.

## Tax

#### `viesCheck(string $countryCode, string $vatNumber, array $trader = [], array $requester = []): ViesResult`

Qualified VIES check: validates an EU VAT id and, when `$trader` details are
passed, returns VIES's registered name and address plus per-field match flags for
a "details do not match" warning. The `consultationNumber` is your audit
reference. Tax computation runs the resolvers' own VIES check; this one is for
the UI warning and the record. See [Tax](/usage/tax).
