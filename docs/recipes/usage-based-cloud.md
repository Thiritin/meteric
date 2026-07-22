# Bill a cloud platform on real usage

A worked example: a cloud platform charges a flat monthly base fee in advance,
plus metered usage for CPU, memory, and traffic billed in arrears. This walks the
meter definitions, two recording styles, the monthly rollup, and the invoice that
carries the prepaid base fee and the arrears usage together.

The mechanics behind each step live in
[Usage billing](/usage/usage-billing),
[Products and prices](/usage/products-and-prices), and
[Invoicing](/usage/invoicing). This page puts them in one flow.

## The product

A metered product. The base fee is a separate fixed product billed in advance;
usage lives on the metered product's `MeterDimension` rows.

```php
use Meteric\Models\{Product, Price};
use Meteric\Enums\{PricingModel, PricePurpose, Interval, BillingMode};

$platform = Product::create([
    'type' => 'cloud',
    'slug' => 'cloud-compute',
    'name' => 'Cloud compute',
    'pricing_model' => PricingModel::Metered,
    'is_proratable' => false,
]);

// Base platform fee, prepaid monthly.
$base = Product::create([
    'type' => 'cloud',
    'slug' => 'cloud-base',
    'name' => 'Platform base fee',
    'pricing_model' => PricingModel::Fixed,
    'is_proratable' => true,
]);

$basePrice = Price::create([
    'product_id' => $base->id,
    'currency' => 'EUR',
    'amount_minor' => 2000,                 // €20.00/mo
    'purpose' => PricePurpose::Recurring,
    'pricing_model' => PricingModel::Fixed,
    'interval' => Interval::Month,
    'interval_count' => 1,
    'billing_mode' => BillingMode::InAdvance,
]);
```

A metered product needs no recurring `Price`. Usage is priced entirely by its
dimensions and always bills in arrears.

## The meter dimensions

Three dimensions, each with its own rate, aggregation, and free allowance.
`rate` is a high-precision `numeric(20,8)` string, never a float, so sub-cent
per-unit pricing stays exact.

```php
use Meteric\Models\MeterDimension;
use Meteric\Enums\Aggregation;

// CPU: €0.012 per hour, 100 hours included.
MeterDimension::create([
    'product_id' => $platform->id,
    'key' => 'cpu_hours',
    'unit' => 'hour',
    'aggregation' => Aggregation::Sum,
    'rate' => '0.01200000',
    'currency' => 'EUR',
    'included_qty' => 100,
]);

// Memory: €0.004 per GB-hour, 200 GB-hours included.
MeterDimension::create([
    'product_id' => $platform->id,
    'key' => 'memory_gb_hours',
    'unit' => 'GB-hour',
    'aggregation' => Aggregation::Sum,
    'rate' => '0.00400000',
    'currency' => 'EUR',
    'included_qty' => 200,
]);

// Traffic: €0.50 per 100 GB block after a 500 GB allowance, capped at €50.
MeterDimension::create([
    'product_id' => $platform->id,
    'key' => 'traffic',
    'unit' => 'GB',
    'aggregation' => Aggregation::Last,   // cycle-to-date counter
    'rate' => '0.50000000',
    'currency' => 'EUR',
    'block_size' => 100,
    'included_qty' => 500,
    'cap_minor' => 5000,                  // never bill more than €50.00/cycle
]);
```

`block_size` bills per block instead of per unit: the overage is divided into
blocks and a started block counts full (`ceil`). `cap_minor` clamps the charge so
a runaway window never bills past the cap. Check the math without recording
anything:

```php
$traffic = $platform->meterDimensions()->where('key', 'traffic')->first();

$traffic->overage(950);     // 450.0  (used 950, 500 free)
$traffic->billedUnits(950); // 5.0    (ceil(450 / 100))
$traffic->amountFor(950);   // Money €2.50  (5 blocks × €0.50)
$traffic->amountFor(99999); // Money €50.00 (clamped to the cap)
```

## Subscribe with the base fee

The customer's subscription carries the base-fee item (prepaid) and the metered
item (usage). The base item accrues its first month; the metered item
accrues nothing on its own, usage drives its charges.

A metered product has no recurring price to add, so attach the metered item with
a zero-amount `Price` whose `pricing_model` is `Metered`, or model the meter on
the base item's product directly. The simplest shape is one metered item per
provisioned instance:

```php
use Meteric\Facades\Meteric;

$meterPrice = Price::create([
    'product_id' => $platform->id,
    'currency' => 'EUR',
    'amount_minor' => 0,
    'purpose' => PricePurpose::Recurring,
    'pricing_model' => PricingModel::Metered,
    'interval' => Interval::Month,
    'interval_count' => 1,
    'billing_mode' => BillingMode::InArrears,
]);

$subscription = Meteric::subscribe($user)
    ->add($basePrice, qty: 1)
    ->add($meterPrice, qty: 1, resource: $instance)
    ->create();

$meterItem = $subscription->items()->where('price_id', $meterPrice->id)->first();
```

## Record usage

### Per-event style (CPU and memory)

Report each meter event as it happens. `recordUsage()` writes a `UsageRecord`
against the item's matching dimension and is idempotent on `key`: the same key
twice returns the existing record instead of double-counting. Use a stable key
per event so retries are safe.

```php
use Carbon\CarbonImmutable;

Meteric::recordUsage(
    item: $meterItem,
    dimension: 'cpu_hours',
    quantity: 4.5,
    occurredAt: CarbonImmutable::now(),
    key: 'cpu-2026-06-19-14',     // window id, meter reading id, anything stable
);

Meteric::recordUsage($meterItem, 'memory_gb_hours', 18.0, key: 'mem-2026-06-19-14');
```

With `Sum` aggregation, every reported quantity adds up over the window.

### Cycle-to-date counter style (traffic)

Some platforms expose traffic as a counter that resets each cycle: query the API
and it returns the total so far. Bill that with `Last` aggregation. Ask
`billingCycle()` for the window so you know what range to query, record the
counter value, and rollup takes the latest reading by `occurred_at` as the cycle
total.

```php
$cycle = Meteric::billingCycle($meterItem);   // a Period, or null before activation

$used = $tenantApi->trafficBetween($cycle->start, $cycle->end); // GB this cycle

Meteric::recordUsage(
    item: $meterItem,
    dimension: 'traffic',
    quantity: $used,
    key: "traffic-{$cycle->start->toDateString()}",  // one key per cycle
);
```

Record the counter once near close, or each time it changes. The next cycle
starts fresh because rollup only reads records inside the window.

## Monthly rollup

At period close, `rollupUsage()` aggregates the window's unbilled records per
dimension, bills the overage (allowance subtracted, cap applied), and stamps the
records as billed. It returns one in-arrears `Charge` per dimension that had
usage.

```php
use Meteric\Support\Period;
use Carbon\CarbonImmutable;

$period = new Period(
    CarbonImmutable::parse('2026-06-01 00:00:00'),
    CarbonImmutable::parse('2026-07-01 00:00:00'),
);

$charges = Meteric::rollupUsage($meterItem, $period);
// one Charge each for cpu_hours, memory_gb_hours, traffic (whichever had usage)
```

The window is reserved per dimension in `meteric_billing_periods`, so a re-run
over the same window is a no-op. The base-fee item renews on its own cycle:

```php
Meteric::renew($subscription, CarbonImmutable::now()); // accrues next month's €20 base fee
```

## The invoice

Both halves land as `pending` charges on the same account: the prepaid base fee
(in advance) and the metered usage (in arrears). One `invoicePending()` call
bills them together.

```php
use Meteric\Models\BillingAccount;

$account = BillingAccount::firstOrCreate(
    ['owner_type' => $user->getMorphClass(), 'owner_id' => $user->getKey()],
    ['currency' => 'EUR'],
);

$invoice = Meteric::invoicePending($account);

foreach ($invoice->lines as $line) {
    // €20.00 base fee, plus one usage line per dimension over its allowance
}
```

The usage lines carry the rolled-up detail in their charge metadata (`used`,
`unit`, `overage`, `block_size`), so you can render "450 GB over allowance, 5
blocks, €2.50" on the invoice. The base fee is a flat recurring line.

Wire the rollup and invoice into a schedule that runs at each customer's cycle
close, then record payment when the gateway confirms it. See
[Invoicing](/usage/invoicing) for the charge-vs-invoice guarantee and payment
handling.

## What the invoice looks like

A cycle invoice for one VM: a prepaid base fee plus one in-arrears line per
metered dimension, each summarising the cycle's usage. With the
[Lexware Office driver](/usage/invoicing#lexware-office-lexoffice) the line title
becomes the lexoffice `name`, the description stays the description, `unit`
becomes `unitName`, and amounts post as **net** with a tax percentage so
lexoffice computes the gross. The numbers below use 19% German VAT. The billed
cycle posts as a service period with an inclusive end (`2026-06-01 to
2026-06-30`, not `to 2026-07-01`).

| Item | Detail | Qty | Unit | Net | VAT | Gross |
|------|--------|-----|------|-----|-----|-------|
| Cloud VM - vm-7f3a.example | 2026-06-01 to 2026-06-30 | 1 | month | €20.00 | €3.80 | €23.80 |
| Cloud VM - vm-7f3a.example | CPU: 312 hours | 312 | hours | €15.60 | €2.96 | €18.56 |
| Cloud VM - vm-7f3a.example | Traffic: 1500 GB | 28 | blocks | €2.50 | €0.48 | €2.98 |
| **Subtotal (net)** | | | | **€38.10** | | |
| **VAT (19%)** | | | | | **€7.24** | |
| **Total (gross)** | | | | | | **€45.34** |

The usage lines are one per dimension, already summed for the cycle. The total
consumed sits in `line->description` (multi-line, with the inclusive period) and
in the charge metadata (`used`, `unit`, `overage`, `block_size`), so
`line->usedSummary()` gives you "1500 GB" for display.
