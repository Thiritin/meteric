# Meteric facade

`Meteric\Facades\Meteric` is the entry point. It resolves `Meteric\Meteric` from
the container. Every method below is a static call on the facade.

```php
use Meteric\Facades\Meteric;
```

## Subscriptions

#### `subscribe(?Model $customer = null): SubscriptionBuilder`

Start building a subscription. Pass the billable customer model, or omit it and
set the account on the builder. See [Subscriptions](/usage/subscriptions).

#### `checkout(?Model $customer = null): SubscriptionBuilder`

Same as `subscribe()`. End the chain with `->checkout()` to create the
subscription and immediately invoice the first cycle.

#### `renew(Subscription $sub, ?CarbonImmutable $at = null): array`

Accrue the next cycle for every due item, rolling forward through elapsed
periods. Idempotent. Returns the created charges.

#### `changePlan(SubscriptionItem $item, Price $newPrice, ?DowngradePolicy $downgrade = null, ?CarbonImmutable $at = null): SubscriptionItem`

Switch an item's plan. Upgrade prorates the difference now; downgrade defers or
discards. See [Plan changes](/usage/plan-changes).

#### `cancel(Subscription $sub, string $at = 'period_end', ?CarbonImmutable $when = null): Subscription`

Cancel at `'period_end'` (default) or `'now'`. No automatic refund.

## Items: addons, options, quantity

#### `addAddon(SubscriptionItem $item, Price $price, ?string $group = null, float $qty = 1, ?CarbonImmutable $at = null): Addon`

Book a prorated addon. Members of the same `group` are swapped (the old one is
credited out).

#### `setOption(SubscriptionItem $item, string $key, string $value, string $type, ?Price $price = null, float $qty = 1, ?CarbonImmutable $at = null): ItemOption`

Set a configurable option (slots, OS, toggle). Prorates the price delta when a
`price` is given.

#### `setQuantity(SubscriptionItem $item, float $qty, ?CarbonImmutable $at = null): SubscriptionItem`

Change an item's base quantity, prorating the difference.

## Commitments

#### `commit(SubscriptionItem $item, Interval $termInterval, int $termCount, Money $upfront, Money $rate, array $earlyTerm = [], ?CarbonImmutable $at = null): Commitment`

Add a term commitment: upfront charge plus a reduced committed rate for the term.

#### `terminateCommitment(Commitment $commitment, ?CarbonImmutable $at = null): Money`

Terminate a commitment early. Returns the fee charged.

## Usage

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

#### `invoicePending(BillingAccount $account, ?string $currency = null): ?Invoice`

Collect an account's pending charges in one currency and issue them via the
bound driver. Returns the invoice, or `null` when nothing is pending. Currency
defaults to the account's.

#### `invoiceConsolidated(BillingAccount $payer, ?string $currency = null): ?Invoice`

Bill the payer's own and all child accounts' pending charges onto a single
invoice, itemized per account.

#### `recordPayment(Invoice $invoice, Money $amount, ?string $reference = null): Payment`

Record an inbound payment against an invoice and advance its state.

#### `driver(): InvoiceDriver`

The bound invoice driver instance.
