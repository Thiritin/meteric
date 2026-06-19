# Usage billing

Metered and hourly billing has two steps. You report usage as it happens with
`recordUsage()`, then close the window with `rollupUsage()`, which aggregates the
records into in-arrears charges. The two steps are separate so reporting stays
cheap and billing stays idempotent.

## Meter dimensions

A usage-based product has one or more `MeterDimension` rows, cpu-hours,
outbound traffic, requests. Each dimension carries its own rate, aggregation,
free allowance, and optional cap.

```php
use Billify\Models\MeterDimension;
use Billify\Enums\Aggregation;

MeterDimension::create([
    'product_id' => $product->id,
    'key' => 'cpu_hours',
    'aggregation' => Aggregation::Sum,
    'rate' => '0.01200000',   // €0.012 per unit, high precision
    'currency' => 'EUR',
    'included_qty' => 100,    // free allowance per window
    'cap_minor' => 5000,      // optional ceiling: never bill more than €50.00
]);
```

`rate` is a `numeric(20,8)` string, never a float, so sub-cent per-unit pricing
stays exact.

### Aggregation

`aggregation` decides how the window's records combine into one billable number:

| `Aggregation` | Combines records by |
|---------------|---------------------|
| `Sum` | Adding every reported quantity (total cpu-hours). |
| `Max` | The largest reported value (peak concurrent slots). |
| `Last` | The last reported value (a gauge). |

### Allowance and cap

`included_qty` is subtracted before charging, the billable quantity is
`max(0, used - included_qty)`. `cap_minor`, if set, clamps the charge so a
runaway window never bills more than the cap.

```php
$dimension->billableQuantity(150); // 50.0 with a 100 allowance
$dimension->amountFor(150);        // Money: round(50 × rate), clamped to the cap
```

## Recording usage

```php
use Billify\Facades\Billify;
use Carbon\CarbonImmutable;

Billify::recordUsage(
    item: $item,
    dimension: 'cpu_hours',
    quantity: 4.5,
    occurredAt: CarbonImmutable::now(),
    key: 'metering-2026-06-19-14',   // idempotency key
);
```

`recordUsage()` writes a `UsageRecord` against the item's matching dimension. It
is idempotent on `key`: report the same key twice and the second call returns the
existing record rather than double-counting. Use a stable key per metering event
(a window id, a meter reading id) so retries are safe. The dimension is resolved
by `key` against the item's product, so it must exist on that product.

## Rolling up

At period close, roll up the window into charges.

```php
use Billify\Support\Period;
use Carbon\CarbonImmutable;

$period = new Period(
    CarbonImmutable::parse('2026-06-01 00:00:00'),
    CarbonImmutable::parse('2026-07-01 00:00:00'),
);

$charges = Billify::rollupUsage($item, $period);
```

`rollupUsage()`:

1. Finds unbilled usage records for the item whose `occurred_at` falls in the
   window. Dimensions are discovered from the records themselves, so usage
   recorded before a plan change (different product) still rolls up.
2. Aggregates each dimension's records per its `aggregation`.
3. Reserves the window in `billify_billing_periods`, keyed by dimension. If the
   window was already billed for that dimension, it skips, no double billing.
4. Creates one in-arrears `Charge` per dimension for the overage (allowance
   subtracted, cap applied) and stamps the records with the charge id.

It returns the charges it created. The reservation is the same GiST-guarded
mechanism that makes [renewal](/usage/subscriptions#renew) idempotent, so a
re-run over the same window is a no-op. After rollup, the charges are `pending`
and get billed by [invoicing](/usage/invoicing) like any other charge.

## Hourly pricing

Hourly is a usage model where the rate is "forward", you bill the hours that
have run, in arrears, at the per-unit rate. Report each hour (or batch of hours)
with `recordUsage()` and roll up the billing window. There is no prepaid value
to prorate, which is why an [hourly plan change](/usage/plan-changes#hourly-and-metered-plans)
takes effect going forward rather than being prorated.
