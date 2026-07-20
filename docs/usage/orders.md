# Orders

An order is a persisted, immutable checkout: one row in `meteric_checkouts`
holding a frozen cart in a single `contents` jsonb column, plus the amounts
computed when it was opened. No `Subscription`, `Charge`, or `Invoice` exists
until the order is paid, and the frozen amounts are the source of truth, so a
later catalog price change never moves a pending order's figures.

One order can hold several items: a webhosting plan and a domain registration in
the same cart. Because each order is a row with a `state`, orders are queryable
for an admin view of pending, paid, and abandoned checkouts.

## When to use an order

`Meteric::quote()` prices a live cart read-only for a checkout page and persists
nothing. The live "edit as you shop" cart belongs to your app. An order is the
place-order moment: `create()` freezes that cart into a row you can pay later.

`Meteric::subscribe()->…->checkout()` subscribes and invoices in one call. It is
separate from orders. Reach for an order when you want a pending, payable cart
that holds its prices until the customer pays.

## Building an order

`createOrder()` returns a `OrderBuilder`. Each `add()` opens a cart line;
`addon()` and `option()` attach to the line most recently added. `create()`
freezes the cart and stores a pending `Order`.

```php
use Meteric\Facades\Meteric;

$order = Meteric::createOrder($customer)
    ->add($hosting, 1, label: 'site.example', group: 'Hosting')
    ->addon($backups, group: 'backups')
    ->option('ram', '1024', 'choice', $ramPrice, label: '1 GB RAM')
    ->add($domainRegister, 1, label: 'example.com', group: 'Domains')
    ->create();
```

This order has two lines. The hosting line carries a backups addon and a RAM
option; the domain line stands alone. An `option` freezes a raw `value` for
provisioning (`'1024'`) and a display `label` (`'1 GB RAM'`) side by side.

### Builder methods

- `account(BillingAccount $account)`: bill to an explicit account. Sets the currency from the account.
- `for(Model $customer)`: the billable customer. Resolves or creates the customer's billing account.
- `currency(string $currency)`: override the currency.
- `anchor(AnchorMode $mode, ?int $day = null)`: align the billing cycle (e.g. `FixedDay, 1`).
- `firstPeriod(FirstPeriodPolicy $policy)`: how the first partial period is billed.
- `trialDays(int $days)`: trial length. A trial defers the first charge and starts the subscription `Trialing`.
- `at(CarbonImmutable $at)`: price as of a fixed instant (deterministic).
- `expiresIn(?int $minutes)`: override the pending TTL. Null leaves the configured default.
- `add(Price $price, float $qty = 1, ?Model $resource = null, ?string $label = null, ?string $group = null)`: open a cart line. `resource` links the line to a host model; `group` tags it for grouped display.
- `addon(Price $price, ?string $group = null, float $qty = 1)`: attach an addon to the current line.
- `option(string $key, string $value, string $type, ?Price $price = null, float $qty = 1, ?float $min = null, ?float $max = null, ?string $label = null)`: attach a configurable option to the current line.
- `create(): Order`: freeze the cart, store a pending order, fire `OrderCreated`.

`create()` throws if the cart is empty or the priced total is negative.

## The order row

```php
$order->total();        // Money: gross owed at checkout (subtotal + tax)
$order->total_minor;    // int: same figure in minor units
$order->state;          // OrderState
$order->contents;       // the frozen cart (array of line entries)
$order->isPending();
$order->isConverted();
```

`Order` maps to the `meteric_checkouts` table. State runs through
`OrderState`:

- `Pending`: open, payable.
- `Converted`: paid or confirmed, materialized into a subscription and invoice.
- `Expired`: passed its TTL before payment.
- `Canceled`: abandoned.

Only `Pending` is non-terminal. The other three are settled and immutable.

## Paying an order

`payOrder()` verifies the amount against the frozen total, then materializes
everything in one transaction: a `Subscription` with its items, addons, and
options, and a Paid invoice built from the frozen amounts.

```php
use Meteric\Facades\Meteric;

$order = Meteric::payOrder($order, $order->total(), ref: 'stripe_pi_123');
```

The amount must equal the order's gross total in the order's currency, or
`payOrder()` throws `InvalidArgumentException`. Paying an order that has
already converted returns it unchanged, so a retried payment never double-bills.
A canceled or expired order is rejected.

On success it fires `OrderPaid` (with the invoice and payment) and
`SubscriptionStarted` (with the order, subscription, and invoice). Hook
`SubscriptionStarted` to provision the service:

```php
use Meteric\Events\SubscriptionStarted;

class ProvisionOnStart
{
    public function handle(SubscriptionStarted $event): void
    {
        foreach ($event->subscription->items as $item) {
            // $item->resource, $item->options -> provision
        }
    }
}
```

### Zero-total orders

A fully trialed signup owes nothing now. Confirm it without a payment:

```php
Meteric::confirmOrder($order);
```

`confirmOrder()` materializes the subscription the same way, with no payment
recorded. It throws if the order is not pending.

## Canceling and expiry

Cancel an abandoned order:

```php
Meteric::cancelOrder($order);
```

This is a no-op once the order is terminal, and fires `OrderCanceled`.

Stale pending orders expire on their own. `create()` stamps `expires_at` from
the checkout TTL (`config('meteric.checkout.ttl_minutes')`, default 1440, one
day). The `meteric:run` tick expires every pending order past its `expires_at`,
sets state `Expired`, and fires `OrderExpired`. To expire on demand outside
the tick:

```php
$count = Meteric::expireOrders();
```

## Events

| Event | When | Payload |
| --- | --- | --- |
| `OrderCreated` | `create()` stores a pending order | `Order` |
| `OrderPaid` | an order is paid or confirmed | `Order`, `?Invoice`, `?Payment` |
| `SubscriptionStarted` | an order materializes its subscription | `Order`, `Subscription`, `?Invoice` |
| `OrderCanceled` | a pending order is canceled | `Order` |
| `OrderExpired` | a pending order passes its TTL | `Order` |
