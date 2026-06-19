# Subscriptions

A subscription is created with the fluent builder returned by
`Meteric::subscribe()`. The builder persists the subscription and its items and
accrues the first cycle as pending charges.

## Subscribe

```php
use Meteric\Facades\Meteric;

$subscription = Meteric::subscribe($user)
    ->add($price)
    ->create();
```

`subscribe($user)` calls `for($user)` on the builder. When you call `create()`
without an explicit account, it resolves the billing account with
`firstOrCreate` keyed on the customer's morph type and id.

Manage the account yourself when you need a specific currency or a parent
account:

```php
use Meteric\Models\BillingAccount;

$account = BillingAccount::create([
    'owner_type' => $user->getMorphClass(),
    'owner_id' => $user->getKey(),
    'currency' => 'CHF',
]);

$subscription = Meteric::subscribe()
    ->account($account)
    ->add($price, qty: 1)
    ->create();
```

`account()` sets the currency from the account. Add several items before
`create()`; each becomes a `SubscriptionItem`.

## Attaching the provisioned resource

A subscription item can morph to the thing it pays for, the actual VPS, domain,
or gameserver record. Pass it as the third argument to `add()`:

```php
$subscription = Meteric::subscribe($user)
    ->add($price, qty: 1, resource: $vps)
    ->create();
```

The item stores `resource_type` and `resource_id`, so you can walk from a
billed line back to the provisioned resource and the other way.

## Trials

```php
$subscription = Meteric::subscribe($user)
    ->add($price)
    ->trialDays(14)
    ->create();
```

A trial sets the subscription state to `trialing` and `trial_end` to now plus
the trial days. During a trial the first cycle is not billed. The builder
reserves the period but defers the charge. The first renewal after the trial
bills it. `isOnTrial()` on the subscription tells you where you stand.

## Anchoring and the first period

Hosting billing rarely starts a customer's cycle on their signup minute. You
anchor the cycle to a calendar boundary and decide how to handle the stub
between signup and that boundary.

```php
use Meteric\Enums\{AnchorMode, FirstPeriodPolicy};

$subscription = Meteric::subscribe($user)
    ->add($price)
    ->anchor(AnchorMode::FixedDay, 1)                 // bill on the 1st
    ->firstPeriod(FirstPeriodPolicy::ProratePlusFull) // stub now + first full month
    ->create();
```

### Anchor modes

| `AnchorMode` | Cycle aligns to |
|--------------|-----------------|
| `Signup` | The anniversary of signup (default). |
| `FixedDay` | A calendar day of month, pass the day to `anchor()`. |
| `FixedDow` | A day of week. |

### First-period policies

| `FirstPeriodPolicy` | What is charged now |
|---------------------|---------------------|
| `ProrateOnly` | The stub from signup to the anchor (default). |
| `ProratePlusFull` | The stub plus the first full period. |
| `FullPeriod` | One full period now, no stub proration. |
| `FreeUntilAnchor` | Nothing now; the stub is free and billing starts at the anchor. |

Anchoring on the 1st with `ProratePlusFull`, a customer who signs up on the 25th
of a €10/month plan is charged a 6-day stub (€2.00) plus the first full month
(€10.00), then €10/month from the 1st.

## Deterministic timing

Every builder method that touches the clock accepts an explicit instant through
`->at()`. This makes tests and replays deterministic.

```php
use Carbon\CarbonImmutable;

$subscription = Meteric::subscribe($user)
    ->add($price)
    ->at(CarbonImmutable::parse('2026-01-25 10:00:00'))
    ->create();
```

## Renew

`Meteric::renew()` accrues the next cycle for every active item, rolling forward
through any periods that elapsed since the last run. It is idempotent: the
billing-period guard prevents billing a window twice, so it is safe to run on a
schedule and safe to re-run.

```php
use Carbon\CarbonImmutable;

$charges = Meteric::renew($subscription, CarbonImmutable::now());
```

It returns the charges it created (empty when nothing was due). A
[deferred plan change](/usage/plan-changes) attached to an item is applied at the
period boundary during renewal. Use the `dueForRenewal` scope to find work:

```php
use Meteric\Models\Subscription;

Subscription::dueForRenewal(CarbonImmutable::now())->get();
```

## Cancel

```php
// At period end (default): set cancel_at, keep billing until then.
Meteric::cancel($subscription);

// Immediately: cancel items now, no refund.
Meteric::cancel($subscription, 'now');
```

Cancellation does not refund. `period_end` sets `cancel_at` to the current
period's end and leaves the subscription billable until then. `now` cancels the
items and the subscription immediately. Neither path moves money.
