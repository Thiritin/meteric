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
`hourly`, `one_off`. `metered` and `hourly` are usage-based, `isMetered()`
returns true for those.

The `config` array holds product-level settings. `config['downgrade']` sets the
default [downgrade policy](/usage/plan-changes); it falls back to `defer`.

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
