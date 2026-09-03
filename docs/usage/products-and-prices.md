# Products and prices

A `Product` is a catalog entry. A `Price` is a versioned way to charge for it.
A product can have many prices, different currencies, different purposes
(recurring, setup, renewal), different points in time.

## Products

```php
use Meteric\Models\Product;
use Meteric\Enums\PricingModel;

$product = Product::create([
    'type' => 'vps',                       // your category, free-form
    'slug' => 'vps-xl',
    'name' => 'VPS XL',
    'pricing_model' => PricingModel::Fixed,
    'is_proratable' => true,
    'config' => ['downgrade' => 'defer'],  // optional per-product downgrade policy
]);
```

`pricing_model` is one of `fixed`, `per_unit`, `tiered`, `volume`, `metered`,
`hourly`, `one_off`, `relative`. `metered` and `hourly` are usage-based,
`isMetered()` returns true for those. `relative` charges a percentage of the
owning item's base price and is used by addons; see
[Relative pricing](/usage/addons-and-options#relative-pricing).

### Product config

The `config` array holds product-level settings. Two keys are read by the
package:

- `config['downgrade']` sets the default [downgrade policy](/usage/plan-changes); it falls back to `defer`. Read it with `downgradePolicy()`.
- `config['cancel_notice_days']` is the notice required before a contract ends, in days; it falls back to `0`. Read it with `cancelNoticeDays()`. See [cancellation](/usage/subscriptions#notice-window).

Both keys are validated on write. `config['downgrade']` must be a valid
`DowngradePolicy` value (`defer`, `discard`, `credit`, `refund`) and
`config['cancel_notice_days']` a non-negative integer, or the assignment throws
`InvalidArgumentException`. Any other key, a provisioner name or another host
setting of your own, passes through untouched.

```php
$product->config = ['downgrade' => 'nope'];  // throws InvalidArgumentException
$product->config = ['provisioner' => 'virtfusion', 'cancel_notice_days' => 30]; // fine
```

## Prices

```php
use Meteric\Models\Price;
use Meteric\Enums\{PricingModel, Interval, BillingMode, PricePurpose};

$price = Price::create([
    'product_id' => $product->id,
    'currency' => 'EUR',
    'amount_minor' => 1000,                // €10.00
    'purpose' => PricePurpose::Recurring,
    'pricing_model' => PricingModel::Fixed,
    'interval' => Interval::Month,
    'interval_count' => 1,
    'billing_mode' => BillingMode::InAdvance,
    'setup_fee_minor' => 0,
]);
```

`amount` is a `Money` accessor over `amount_minor` + `currency`. Read it back as
money rather than touching the integer:

```php
$price->amount;            // Money €10.00
$price->setupFee();        // Money (0 if no setup fee)
$price->isRecurring();     // bool, false for one-off prices
$price->hasSetupFee();     // bool
```

### Billing mode

`billing_mode` is `in_advance` (prepaid, charged at period start) or
`in_arrears` (postpaid, charged at period end). Usage and hourly prices bill in
arrears regardless. An item can override the price's mode; otherwise the price's
mode wins, falling back to `in_advance`.

### Price purposes

`purpose` lets one product carry separate prices for different events:
`recurring`, `setup`, `register`, `renew`, `transfer`, `addon`, `option`. Domain
billing uses this, a register price and a renew price on the same product.

```php
use Meteric\Enums\PricePurpose;

// The current recurring price for a currency.
$price = $product->priceFor('EUR');

// A different purpose.
$renew = $product->priceFor('EUR', PricePurpose::Renew);
```

`priceFor()` returns the latest price with no `valid_to` for that currency and
purpose, so superseding a price is a matter of inserting a new row and closing
the old one with `valid_to`.

### Terms

A product sold on several terms carries one recurring price per interval:
monthly, quarterly, yearly. Pass the interval to `priceFor()` to pick one;
without it the newest current price wins whatever its term.

```php
use Meteric\Enums\Interval;

$monthly = $product->priceFor('EUR', PricePurpose::Recurring, Interval::Month);
$quarterly = $product->priceFor('EUR', PricePurpose::Recurring, Interval::Month, 3);
$yearly = $product->priceFor('EUR', PricePurpose::Recurring, Interval::Year);
```

`terms($currency)` lists the current recurring prices, one per term, shortest
first. `termCatalog($currency, $qty)` renders the same list as JSON-ready rows
for a term picker, each priced at `$qty`:

```php
$product->termCatalog('EUR');
// [
//   ['price_id' => …, 'interval' => 'month', 'interval_count' => 1,
//    'amount_minor' => 1000, 'amount' => '10.00', 'currency' => 'EUR',
//    'setup_fee_minor' => 2500, 'pricing_model' => 'fixed', …],
//   ['price_id' => …, 'interval' => 'year', 'interval_count' => 1, …],
// ]
```

Each row is `Price::toDisplay($qty)`: the price at the quantity plus the raw
pricing knobs (`unit_rate`, `percent`, `included_qty`, `block_size`, `tiers`),
so a client can recompute as the quantity changes.

### Setup fee

`setup_fee_minor` on a recurring price is a one-time fee owed with the first
period. An [order](/usage/orders) freezes it beside the first period's amount
and charges it once as a `setup` line when the order is paid; renewals never
bill it again. A trial defers the recurring part and still owes the setup.

### Per-unit and sub-cent rates

For per-unit, metered, and hourly pricing, set `unit_rate` instead of (or
alongside) `amount_minor`. It is a high-precision numeric string, so you can
price below a cent per unit without float drift.

```php
$price = Price::create([
    'product_id' => $product->id,
    'currency' => 'EUR',
    'unit_rate' => '0.00004200',    // €0.000042 per unit
    'purpose' => \Meteric\Enums\PricePurpose::Recurring,
    'pricing_model' => \Meteric\Enums\PricingModel::PerUnit,
]);

$price->amountFor(100000);          // Money, round(qty × unit_rate)
```

`amountFor($qty)` multiplies by `unit_rate` when set, otherwise by the flat
`amount`. Usage caps and allowances live on the
[meter dimension](/usage/usage-billing).

A price also carries the usage-style knobs `included_qty` (free allowance),
`block_size` (bill per started block of N units), `cap_minor`, and
`min_charge_minor`. `amountForQuantity($qty)` applies those on top of `amountFor`;
[options and addons](/usage/addons-and-options#allowance-blocks-and-caps-on-options)
bill through it.

### Quantity discounts (tiers)

To make a quantity cheaper as it grows, set the `tiers` table and a tiered
pricing model. A tier is `{ up_to, unit_minor }`, ordered low to high, where
`up_to: null` is the last, unbounded tier.

```php
$price = Price::create([
    'product_id' => $product->id,
    'currency' => 'EUR',
    'pricing_model' => PricingModel::Volume,   // or Tiered
    'tiers' => [
        ['up_to' => 10,   'unit_minor' => 500], // 1 to 10 at €5
        ['up_to' => 50,   'unit_minor' => 400], // 11 to 50 at €4
        ['up_to' => null, 'unit_minor' => 300], // 51+ at €3
    ],
]);
```

Two models, picked by `pricing_model`:

- **`Volume`**: the whole quantity is priced at the tier it lands in. 60 units
  bills `60 × €3 = €180`. This is the usual "the more you buy, the cheaper" deal.
- **`Tiered`**: each slice is priced at its own tier, then summed. 60 units bills
  `10 × €5 + 40 × €4 + 10 × €3 = €240`.

This runs through `amountFor()`, so it applies anywhere a quantity is priced:
base items, configurable options (slots, extra IPs), and addons.

See also: [Build a web hosting company's billing](/recipes/web-hosting-company)
for a full catalog (plans, setup fees, domains, addons, volume-priced IPs).

## Price overrides: one item, a bespoke amount

A subscription item can bill an amount its product does not publish.

```php
Meteric::overridePrice($item, 600);   // this item bills 6.00, whatever the plan says
Meteric::clearPriceOverride($item);   // back to the product's price
```

The override is **a price row of its own**, not an amount column on the item.
`Price::asOverride()` copies the catalog price and changes one field, so the override
carries the same product, currency, interval, billing mode, purpose and pricing model as
the price it replaces, and behaves identically in every calculation that reads it.
`SubscriptionItem::price` resolves to it, so proration, plan swaps, the accruer, checkout
and the relative addon prices that compute against the item's base all pick it up without
knowing overrides exist.

**That is the whole reason for the shape.** `$item->price->amountFor(...)` is read at two
dozen places across the managers, the accruer, the prorator and the pricers. An amount
column would have had to be honoured at every one of them, and the call site that forgot
would be a silent money bug - the relative addon prices most quietly of all, since an
override changes what every percentage addon computes against.

**An override is not a catalog price**, and the difference is enforced rather than left to
convention. `prices.scope` is `catalog` or `override`; `Price::query()->catalog()` is what
every listing, report, export and selection filters on, and `Product::currentPrices()` -
which `priceFor()` and the addon resolution run through - applies it. An override is
therefore never offered for sale, never grandfathered, never replaced by the catalog's
price-replacement flow, and never listed beside the prices a product sells at.

From the outside this looks like the other design, the one where you write a bespoke price
into the catalog and keep it out of listings by remembering to. It is not that design.
Convention fails at the next listing somebody adds; a flag does not, provided the flag is
actually consulted - which is why `catalog()` exists as a query scope rather than as a
`where` clause copied around.

**It resets on a plan change.** `price_id` still names the plan the item is on, and moving
the item onto another price clears `price_override_id` beside it. An amount agreed against
one plan does not silently follow the customer onto another.

**The override row is kept, never deleted.** Clearing an override drops the reference and
leaves the price row in place, and the foreign key is `restrictOnDelete`. A charge or an
invoice line written against an override has to resolve years later; a deleted price under
an archived invoice is the kind of thing that breaks a document nobody looks at until an
audit.

**Setting an override does not move money.** It changes what the next accrual bills; the
running period was already charged at whatever it was charged. A caller that wants the
difference settled mid-period rebases or prorates on top of it, deliberately.
