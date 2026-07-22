# Configuration

`config/meteric.php` is published with `vendor:publish --tag=meteric-config`.
Nearly every key reads from an env var, so most setup is environment-driven.
The exception is `schema.prefix`, a plain literal you edit in the published file.

## Currency

```php
'currency' => env('METERIC_CURRENCY', 'EUR'),
```

The default currency for new billing accounts and the quote builder. A billing
account stores its own currency, which overrides this once set.

## Proration

```php
'proration' => [
    'unit' => env('METERIC_PRORATION_UNIT', 'second'), // second | day
],
```

`second` is the default and is DST- and leap-safe. `day` rounds proration to
whole days.

## Rounding

```php
'rounding' => env('METERIC_ROUNDING', 'HALF_UP'),
```

Applied per line. The invoice total is the sum of line totals, so it always
reconciles. Use any `brick/math` `RoundingMode` name.

Anchoring and the first-period policy are set per subscription on the builder;
see [Subscriptions](/usage/subscriptions).

## Tax driver

```php
'tax' => [
    'driver' => env('METERIC_TAX_DRIVER', 'database'),
    'drivers' => [
        'database'  => DatabaseTaxResolver::class,
        'ibericode' => IbericodeVatResolver::class,
        'eu_vat'    => EuVatResolver::class,
        'flat'      => FlatRateTaxResolver::class,
        'null'      => NullTaxResolver::class,
    ],
    'flat_rate' => env('METERIC_TAX_FLAT_RATE', 0.19),
    'merchant_country' => env('METERIC_MERCHANT_COUNTRY', 'DE'),
    'ibericode' => [
        'storage_path' => env('METERIC_VAT_RATES_PATH', storage_path('framework/cache/meteric-vat-rates.json')),
        'refresh_interval' => (int) env('METERIC_VAT_REFRESH', 12 * 3600),
        'verify_vat_id' => env('METERIC_VERIFY_VAT_ID', true),
    ],
],
```

- `database` (default) is a configurable multi-jurisdiction rate table with
  registrations. EU rows are fed by ibericode; you add CH, UK, and others by hand.
- `ibericode` is live EU-only rates plus VIES verification.
- `eu_vat` is a static offline EU fallback.
- `flat` and `null` are for tests.

See [Tax](/usage/tax) for the full setup.

## Invoice driver

```php
'invoice' => [
    'driver' => env('METERIC_INVOICE_DRIVER', 'database'),
    'drivers' => [
        'database' => DatabaseInvoiceDriver::class,
        'lexoffice' => LexofficeInvoiceDriver::class,
    ],
],
```

The `database` driver writes invoices to the `meteric_*` tables. Bind your own
class implementing `Meteric\Contracts\InvoiceDriver` (or select the bundled
`lexoffice` driver) to also push invoices to an external accounting system; the
local invoice is always the source of truth. An unknown driver key throws. See
[Invoicing](/usage/invoicing).

## Schema

```php
'schema' => [
    'prefix' => 'meteric_',
],
```

`prefix` is prepended to every table name (`meteric_subscriptions`,
`meteric_invoices`, and so on). Set it to your own prefix, or to `''` for
unprefixed tables (`subscriptions`, `invoices`). Hand-named constraints and
indexes keep a fixed `meteric_` spelling, but auto-derived enum and currency
CHECK names follow the prefixed table name. Set the prefix before the first
migration; changing it after the tables exist leaves the old tables behind.

## Swapping models

Every Meteric model can be replaced with a host-app subclass. Register the
overrides once (e.g. in a service provider's `register()`):

```php
use Meteric\Facades\Meteric;

Meteric::useInvoiceModel(App\Models\Invoice::class);
```

See [Extending](/usage/extending#swapping-models) for the full list of helpers.
