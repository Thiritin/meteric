# Configuration

`config/meteric.php` is published with `vendor:publish --tag=meteric-config`.
Every key reads from an env var, so most setup is environment-driven.

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

## Anchoring and first period

```php
'anchor' => [
    'mode' => env('METERIC_ANCHOR_MODE', 'signup'),   // signup | fixed_day | fixed_dow
    'day' => env('METERIC_ANCHOR_DAY', 1),
    'first_period' => env('METERIC_FIRST_PERIOD', 'prorate_only'),
    'default_billing_mode' => 'in_advance',
],
```

Global defaults for how the first billing period is anchored and billed. Each
subscription can override these on the builder. See
[Subscriptions](/usage/subscriptions) for the policies.

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
        // 'lexoffice' => \App\Billing\LexofficeInvoiceDriver::class,
    ],
    'mirror_to_database' => env('METERIC_INVOICE_MIRROR', true),
],
```

The `database` driver writes invoices to the `meteric_*` tables. Bind your own
class implementing `Meteric\Contracts\InvoiceDriver` to send invoices to an
external accounting system. With `mirror_to_database` on, the canonical record
is kept in the database even when a remote driver is primary. See
[Invoicing](/usage/invoicing).

## Schema

```php
'schema' => [
    'prefix' => 'meteric_',
    'morph_key' => env('METERIC_MORPH_KEY', 'uuid'), // uuid | bigint
],
```

`morph_key` is the key type used for host references (the morph columns that
point at your models) and Meteric's own primary keys. Set it to match your
application's key type before the first migration.

## Ledger

```php
'ledger' => [
    'enabled' => env('METERIC_LEDGER', false),
],
```

Off by default. Enables the double-entry audit ledger.
