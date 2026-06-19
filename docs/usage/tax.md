# Tax

Tax resolution is a swappable driver. The default `database` driver is a
configurable engine over two editable tables, so you control which jurisdictions
you charge in and at what rate. The other drivers are simpler fallbacks for EU
or for tests.

## How the database driver decides

Tax is charged only where the merchant is **registered**. The logic runs in this
order for a given amount and customer context:

1. EU cross-border B2B with a verified VAT id → reverse charge, no tax.
2. No registration covering the customer's country → out of scope, no tax.
3. Otherwise → the rate from the rate table for that country, date, and product
   category.

Two tables drive it:

- `billify_tax_registrations`, the jurisdictions you are VAT-registered in. A
  direct country row, or an `eu_oss` row that covers all EU destinations. No
  registration for the customer's country means no tax is charged.
- `billify_tax_rates`, date-versioned rates per country and product category.
  EU rows are refreshed from ibericode; non-EU jurisdictions are added by hand.

## Switzerland example

Register for Swiss VAT and add its rates:

```php
use Billify\Models\{TaxRegistration, TaxRate};

TaxRegistration::create([
    'country' => 'CH',
    'scheme' => 'ch_vat',
    'number' => 'CHE-123.456.789 MWST',
]);

TaxRate::create([
    'country' => 'CH',
    'category' => 'standard',
    'rate' => '0.081000',          // 8.1%, stored as a fraction string
    'effective_from' => '2024-01-01',
]);

TaxRate::create([
    'country' => 'CH',
    'category' => 'lodging',
    'rate' => '0.038000',          // 3.8% reduced rate
    'effective_from' => '2024-01-01',
]);
```

Now Swiss customers are charged 8.1% (3.8% for `lodging` products), EU customers
still go through OSS, and customers elsewhere are untaxed until you register
there. The `category` matches the product's tax class, set it on the
`TaxContext` to bill a reduced rate.

`rate` is a `numeric(8,6)` fraction stored as a string. Rates are date-versioned:
superseding a rate means closing the old row with `effective_to` and inserting a
new one, which the rate table's `activeOn` scope reads back correctly.

## EU rates and VIES

EU rates come from `ibericode/vat`. Cross-border B2B reverse charge is confirmed
against VIES when a validator is available, so a business customer in another EU
country with a valid VAT id is reverse-charged rather than taxed. Turn VIES
verification off with `BILLIFY_VERIFY_VAT_ID=false`, which then trusts the mere
presence of a VAT id.

## Keeping EU rates current

`billify:vat-sync` refreshes the EU rows of `billify_tax_rates` from ibericode.
It only touches rows with `source = 'ibericode'`, your manual jurisdictions
(CH, UK, anything else) are never modified. When a rate changes, the old row is
closed with `effective_to` and a new current row is inserted, so history is kept.

```bash
php artisan billify:vat-sync                      # standard + reduced
php artisan billify:vat-sync --category=standard  # one category
```

Run it on a schedule so EU rates stay fresh:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('billify:vat-sync')->weekly();
```

## Other drivers

| `BILLIFY_TAX_DRIVER` | Behaviour |
|----------------------|-----------|
| `database` (default) | Multi-jurisdiction registrations + rate table, EU via ibericode + VIES. |
| `ibericode` | Live EU-only rates plus VIES, no rate table. |
| `eu_vat` | Static offline EU rates. Good for tests with no network. |
| `flat` | One flat rate (`BILLIFY_TAX_FLAT_RATE`). |
| `null` | No tax. |

Bind your own resolver by implementing `Billify\Contracts\TaxResolver` and
adding it to the `tax.drivers` map. Keeping rates legally correct is the host's
responsibility; the engine makes it manageable.

## Passing tax context

The resolver needs to know where the customer is. A `BillingAccount` carries a
`tax_profile`, and `taxContext()` turns it into a `TaxContext`:

```php
$context = $account->taxContext();
// or build one directly for a quote:
$context = new \Billify\Tax\TaxContext(
    countryCode: 'CH',
    isBusiness: true,
    vatId: 'CHE-123.456.789',
    category: 'standard',
);
```

Pass it to [`Billify::quote()->tax(...)`](/usage/quotes-and-checkout) to render
tax-correct totals on a checkout page.
