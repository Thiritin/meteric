# Billify

[![tests](https://github.com/Thiritin/billify/actions/workflows/tests.yml/badge.svg)](https://github.com/Thiritin/billify/actions/workflows/tests.yml)

Dynamic billing engine for hosting systems — subscriptions, proration, usage
metering, addons, and the charge-vs-invoice safety model, behind a well-tested,
PostgreSQL-backed package. Inspired by Stripe Billing + WHMCS, sized for hosts
running VPS, domains, webhosting, cloud, gameservers and IP projects.

> **Status:** scaffold. Schema, models, drivers, proration and the invoicing
> guarantee are in place. The fluent `subscribe()/quote()/checkout()` builders
> are the next milestone (see [Roadmap](#roadmap)). Design lives in
> [`DESIGN.md`](DESIGN.md); schema in [`SCHEMA.md`](SCHEMA.md).

## Why

Most hosting billing tangles money math into the app. Billify isolates it:

- **Charge ≠ invoice.** Charges accrue as the source of truth; an invoice is a
  document that bills them. If your accounting system (e.g. Lexware Office) is
  down, charges stay `pending` — no revenue lost, retried next run.
- **Pluggable drivers.** Invoice emission and tax are swappable contracts.
  Ships a database invoice driver + an EU VAT resolver; bind your own.
- **PostgreSQL-native safety.** A GiST `EXCLUDE` constraint makes it physically
  impossible to bill the same service window twice.
- **No floats.** Money is integer minor units via `brick/money`.

## Requirements

- PHP 8.5+
- Laravel 12
- PostgreSQL 13+ (uses `tstzrange`, `btree_gist`, `pgcrypto`, enum types)

## Install

```bash
composer require pawhost/billify
php artisan vendor:publish --tag=billify-config
php artisan migrate
```

## Core concepts

| Concept | What it is |
|---------|-----------|
| `Product` / `Price` | Catalog + versioned pricing (recurrence, billing mode, tiers, caps). |
| `Subscription` / `SubscriptionItem` | A customer commitment and its billed lines; items morph to the provisioned resource. |
| `Addon` / `ItemOption` | Bookable extras (+4GB RAM) and configurable dimensions (gameserver slots). |
| `MeterDimension` / `UsageRecord` | Multi-dimension usage (cpu-hours, traffic) for hourly/metered billing. |
| `Charge` | Money owed. Accrues `pending`, flips to `invoiced` only on driver success. |
| `Invoice` / `InvoiceLine` | Immutable document; each line carries its own service period. |

## The invoicing guarantee

```php
use Billify\Facades\Billify;
use Brick\Money\Money;

// Collect an account's pending charges and issue them via the bound driver.
// If the driver throws (e.g. accounting system down), charges stay `pending`.
$invoice = Billify::invoicePending($account);

// Inbound payment from your gateway drives invoice state.
Billify::recordPayment($invoice, Money::of('49.98', 'EUR'), 'pi_123');
```

## Custom drivers

```php
// config/billify.php
'invoice' => [
    'driver' => 'lexoffice',
    'drivers' => [
        'database'  => \Billify\Invoicing\Drivers\DatabaseInvoiceDriver::class,
        'lexoffice' => \App\Billing\LexofficeInvoiceDriver::class,
    ],
],
'tax' => [
    // ibericode = live EU rates + VIES (default) · eu_vat = static offline
    // flat | null | your own
    'driver' => 'ibericode',
],
```

A driver implements `Billify\Contracts\InvoiceDriver`. Throwing from `issue()`
is the failure boundary that preserves pending charges.

### VAT — configurable, multi-jurisdiction

The default `database` driver is a configurable engine over two editable tables:

- **`billify_tax_registrations`** — where you're VAT-registered. No registration
  in the customer's country ⇒ no tax charged (out of scope). An `eu_oss` row
  covers all EU destinations.
- **`billify_tax_rates`** — date-versioned rates per country + product category.
  EU rows are refreshed from ibericode by `php artisan billify:vat-sync`; non-EU
  jurisdictions are added manually.

Registering for **Swiss VAT**:

```php
use Billify\Models\{TaxRegistration, TaxRate};

TaxRegistration::create(['country' => 'CH', 'scheme' => 'ch_vat', 'number' => 'CHE-123.456.789 MWST']);
TaxRate::create(['country' => 'CH', 'category' => 'standard', 'rate' => '0.081000', 'effective_from' => '2024-01-01']);
TaxRate::create(['country' => 'CH', 'category' => 'lodging',  'rate' => '0.038000', 'effective_from' => '2024-01-01']);
```

Now CH customers are charged 8.1% (3.8% for lodging products); EU customers still
go through OSS/ibericode; everyone else is untaxed unless you register there. EU
cross-border B2B reverse charge is confirmed via **VIES**.

Keep the EU rows current automatically:

```bash
php artisan billify:vat-sync   # ibericode → billify_tax_rates (manual rows untouched)
```

Run it on a schedule so EU rates stay fresh (in your app's `routes/console.php`
or scheduler):

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('billify:vat-sync')->weekly();
```

Other drivers: `ibericode` (live EU-only + VIES), `eu_vat` (static offline,
good for tests), `flat`, `null`, or your own `TaxResolver`. Keeping rates legally
correct is ultimately the host's responsibility; the engine makes it manageable.

## Proration & quoting

`Prorator` computes second-precision proration for upgrades/downgrades and
mid-cycle changes.

`Billify::quote()` renders a **due-now + recurring breakdown for checkout pages**
without persisting anything — same planner/prorator/tax stack as real billing, so
the quote always matches the eventual invoice:

```php
$quote = Billify::quote()
    ->anchor(AnchorMode::FixedDay, 1)            // align to the 1st
    ->firstPeriod(FirstPeriodPolicy::ProratePlusFull) // stub + first full month
    ->tax(new TaxContext(countryCode: 'DE'))
    ->at($checkoutInstant)                        // deterministic
    ->add($vpsPrice, qty: 1, label: 'VPS XL')
    ->build();

return $quote->toArray(); // JSON for the frontend: due_now, recurring, lines[]
```

Order a €10/mo plan on the 25th with `prorate_plus_full` → due now = 6-day stub
(€2.00) + first full month (€10.00); then €10/mo from the 1st. Usage/metered
lines come back flagged `estimated` with their rate.

## Testing

```bash
composer test        # Pest
composer analyse     # Larastan
vendor/bin/pint      # format
```

## Roadmap

- [x] Schema (hybrid Laravel Blueprint + `tpetry/laravel-postgresql-enhanced`), models, casts, enums
- [x] Tax drivers (EU VAT, flat, null) + database invoice driver
- [x] Proration engine + charge/invoice/payment flow
- [x] Test suite — 34 tests green (unit calc core + feature against real Postgres:
      migrations, GiST no-double-bill guard, immutability trigger, invoicing flow)
- [x] Configurable multi-jurisdiction tax (registrations + rate table, Swiss-ready) + `billify:vat-sync`
- [x] Anchoring / first-period planner (signup, fixed-day, prorate / prorate+full / free-until-anchor)
- [x] `quote()` builder — due-now + recurring breakdown as JSON for checkout
- [x] `subscribe()` — persists subscription + items, accrues first cycle (trial defer, idempotent guard)
- [x] `renew()` / `changePlan()` (prorated or deferred) / `cancel()` (now or period-end)
- [x] `checkout()` (subscribe → immediate invoice)
- [x] Usage rollup — metered/hourly `recordUsage()` + `rollupUsage()` (allowance, cap, in-arrears, idempotent)
- [x] Addons / configurable options / quantity — prorated mid-cycle via `addAddon()` / `setOption()` / `setQuantity()`
- [x] Commitments (`commit()` term + upfront + committed rate + early-termination)
- [x] Consolidated billing (`invoiceConsolidated()` — payer + child accounts on one invoice)
- [x] CI: pg-backed Pest + Pint + PHPStan on every push/PR (76 tests green)
- [ ] Polish: more `@property` annotations to shrink the PHPStan baseline; per-UC test coverage from `DESIGN.md`

## License

MIT.
