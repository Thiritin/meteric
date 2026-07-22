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
use Meteric\Models\MeterDimension;
use Meteric\Enums\Aggregation;

MeterDimension::create([
    'product_id' => $product->id,
    'key' => 'cpu_hours',
    'unit' => 'hour',
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
| `Last` | The latest reported value by `occurred_at` (a gauge or a cycle-to-date counter). |

### Allowance, cap, and unit

`included_qty` is the free allowance, subtracted before charging. The overage is
`max(0, used - included_qty)`. `cap_minor`, if set, clamps the charge so a
runaway window never bills more than the cap. `unit` is a label (`GB`, `TB`,
`requests`) carried onto the charge metadata for formatting later.

```php
$dimension->overage(150);    // 50.0 with a 100 allowance
$dimension->amountFor(150);  // Money: round(billed units × rate), clamped to the cap
```

### Per-unit or per-block pricing

By default the rate is per unit of overage. Set `block_size` to bill per block
instead: the overage is divided into blocks and a started block counts full
(`ceil`). The rate is then the price per block.

```php
MeterDimension::create([
    // ... product, key, unit ...
    'included_qty' => 100,        // first 100 TB free
    'block_size'   => 50,         // bill per 50 TB block
    'rate'         => '5.00',     // €5 per block
]);
```

With 100 TB free and €5 per 50 TB block: 101 to 150 TB bills one block (€5), 151
to 200 TB bills two (€10). The charge's `quantity` is the number of blocks, and
its metadata holds `used`, `unit`, `overage`, and `block_size`.

## Recording usage

```php
use Meteric\Facades\Meteric;
use Carbon\CarbonImmutable;

Meteric::recordUsage(
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

### Cycle-to-date counters

Some platforms expose usage as a counter that resets each billing cycle: query
the tenant API and it returns the cycle total so far. Bill that with `Last`
aggregation. Record the counter value as it changes (or once near close), and
rollup takes the latest reading by `occurred_at` as the cycle total. The next
cycle starts fresh because rollup only reads records inside the cycle window.

`billingCycle()` gives you that window, so you know what range to ask the API for:

```php
$cycle = Meteric::billingCycle($item);   // a Period, or null before activation

$used = $tenantApi->trafficBetween($cycle->start, $cycle->end); // cycle to date

Meteric::recordUsage($item, 'traffic', $used, key: "traffic-{$cycle->start->toDateString()}");
```

## Rolling up

At period close, roll up the window into charges.

```php
use Meteric\Support\Period;
use Carbon\CarbonImmutable;

$period = new Period(
    CarbonImmutable::parse('2026-06-01 00:00:00'),
    CarbonImmutable::parse('2026-07-01 00:00:00'),
);

$charges = Meteric::rollupUsage($item, $period);
```

`rollupUsage()`:

1. Finds unbilled usage records for the item whose `occurred_at` falls in the
   window. Dimensions are discovered from the records themselves, so usage
   recorded before a plan change (different product) still rolls up.
2. Aggregates each dimension's records per its `aggregation`.
3. Reserves the window in `meteric_billing_periods`, keyed by dimension. If the
   window was already billed for that dimension, it skips, no double billing.
4. Creates one in-arrears `Charge` per dimension for the overage (allowance
   subtracted, cap applied) and stamps the records with the charge id.

It returns the charges it created. The reservation is the same GiST-guarded
mechanism that makes [renewal](/usage/subscriptions#renew) idempotent, so a
re-run over the same window is a no-op. After rollup, the charges are `pending`
and get billed by [invoicing](/usage/invoicing) like any other charge.

## Scheduling

Meteric does not collect metrics or run a clock of its own. You own the schedule.
Your job reads your metrics source in one batch, computes each item's value in
PHP, and pushes it. A platform like OpenStack fits the batch shape: fetch every
server once, compute, record.

```php
// routes/console.php (or a scheduled job)
use Illuminate\Support\Facades\Schedule;

Schedule::call(function () {
    $servers = OpenStack::servers();                 // one batch call

    foreach (SubscriptionItem::whereHas('product', fn ($q) => $q->where('type', 'vps'))->with('subscription')->cursor() as $item) {
        $mbps = percentile95($servers[$item->resource_id]->bandwidthSamples());
        Meteric::recordUsage($item, 'bandwidth', $mbps);
    }
})->dailyAt('02:00');
```

For 95th-percentile (burstable) bandwidth, sample the average rate at a fixed
interval, 5 minutes is the convention, and compute the percentile in your job
over those samples, then record the one number. Meteric stores and prices it; the
sampling cadence and statistic live in your collector.

One command runs the whole billing tick. `meteric:run`, for each subscription
whose period has ended, rolls up the elapsed usage window into charges, renews
(accrues the next cycle), issues an invoice per affected account, and flags any
past-due invoices overdue. Every step is idempotent (the billing-period guard and
the overdue guard), so schedule it on a short interval:

```php
// routes/console.php or the scheduler
Schedule::command('meteric:run')->everyFiveMinutes();
```

It only acts when a cycle has actually closed, so a frequent schedule keeps
billing prompt without doing redundant work. Mixed old and new rates inside a
cycle bill correctly on their own: each usage record carries the dimension it was
recorded against, so a rate change mid-cycle bills the earlier usage at the old
rate and the later usage at the new one with no manual cutover.

## Hourly pricing

Hourly is a usage model where the rate is "forward", you bill the hours that
have run, in arrears, at the per-unit rate. Report each hour (or batch of hours)
with `recordUsage()` and roll up the billing window. There is no prepaid value
to prorate, which is why an [hourly plan change](/usage/plan-changes#hourly-and-metered-plans)
takes effect immediately rather than being prorated.

See also: [Bill a cloud platform on real usage](/recipes/usage-based-cloud) and
[Bill a gameserver per slot and per hour](/recipes/gameserver-slots) for
end-to-end metering examples.
