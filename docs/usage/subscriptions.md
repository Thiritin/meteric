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

A trial sets the subscription state to `trialing` and `trial_end` to the start
instant plus the trial days. During a trial the first cycle is not billed. The builder
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
    ->firstPeriod(FirstPeriodPolicy::ProratePlusFull) // stub plus first full month
    ->create();
```

### Anchor modes

| `AnchorMode` | Cycle aligns to |
|--------------|-----------------|
| `Signup` | The anniversary of signup (default). |
| `FixedDay` | A calendar day of month, pass the day to `anchor()`. |
| `FixedDow` | A day of week. |

### First-period policies

| `FirstPeriodPolicy` | What is charged upfront |
|---------------------|---------------------|
| `ProrateOnly` | The stub from signup to the anchor (default). |
| `ProratePlusFull` | The stub plus the first full period. |
| `FullPeriod` | One full period upfront, no stub proration. |
| `FreeUntilAnchor` | Nothing upfront; the stub is free and billing starts at the anchor. |

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

## Rebase a period

Staff sometimes move a service's paid-until date by hand: a goodwill extension,
a migration that shifts the anniversary, a term corrected after the fact.
`rebasePeriod()` sets an item's current period to `[start, newEnd)`, keeping
the start, and moves the subscription's period along with it (the earliest
active item's period, the same rule renewals use).

```php
use Carbon\CarbonImmutable;

$item = Meteric::rebasePeriod($item, CarbonImmutable::parse('2026-09-15'));
```

Without `$prorate` the dates move and no money does. With `$prorate` the span
between the old end and the new one is charged at the item's full period rate
as one pending line: kind `Prorated` when extended, `Credit` (negative) when
shortened. The span is priced as whole cycles at the full amount plus the used
part of the cycle the remainder starts, prorated through the configured unit.

```php
$item = Meteric::rebasePeriod($item, $newEnd, prorate: true);
```

Preview the figure before a person confirms it:

```php
$preview = Meteric::previewRebase($item, $newEnd);

$preview->period;   // Period the item would move to
$preview->kind;     // LineKind::Prorated, LineKind::Credit, or null when the end does not move
$preview->amount;   // Money, absolute; a credit is written negated
$preview->toArray();
```

`previewRebase()` writes nothing and runs the same guards. Both throw
`Meteric\Exceptions\PeriodNotRebasable` when the item is not active, has no
period, is not recurring, or `$newEnd` is not after the period start.

Signatures:

```php
rebasePeriod(SubscriptionItem $item, CarbonImmutable $newEnd, bool $prorate = false, ?CarbonImmutable $at = null): SubscriptionItem
previewRebase(SubscriptionItem $item, CarbonImmutable $newEnd, ?CarbonImmutable $at = null): RebasePreview
```

Moving an item to another term mid-period is a different operation:
`rebasePeriod()` moves an end and charges the span at the item's own rate,
while [`switchTerm()`](/usage/plan-changes#term-changes) settles the running
period and opens a new one on the new price's term.

## Cancel

```php
use Carbon\CarbonImmutable;

// At period end (default): set cancel_at, keep billing until then.
Meteric::cancel($subscription);

// Immediately: cancel items right away, no refund.
Meteric::cancel($subscription, 'now');

// At a specific future term boundary.
Meteric::cancel($subscription, CarbonImmutable::parse('2026-12-01'));
```

Cancellation does not refund; no path moves money.

`now` cancels the items and the subscription immediately (state `Canceled`,
fires `SubscriptionCanceled`).

`period_end` and a boundary date schedule the cancel: they set `cancel_at` and
leave the subscription billable until that boundary. Renewal stops accruing on or
after `cancel_at`. The `meteric:run` tick enacts the cancel once the boundary
passes (state `Canceled`, fires `SubscriptionCanceled`).

### Cancellation reason

Pass `meta` to attach data to the cancel, a reason or a survey answer. It is
stored on the subscription metadata under `cancellation` and survives the
scheduled enactment, so the reason is still there when `meteric:run` flips the
state.

```php
Meteric::cancel($subscription, 'period_end', meta: ['reason' => 'moving away']);

$subscription->metadata['cancellation']; // ['reason' => 'moving away']
```

### Notice window

A product can require notice before a contract ends with the `cancel_notice_days`
key in its `config`. The notice window is the strictest value across the
subscription's active items. Scheduling a cancel to a boundary inside that window
throws `InvalidArgumentException`:

```php
// Throws if today is within cancel_notice_days of the period end.
Meteric::cancel($subscription, 'period_end');
```

To offer a "cancel at end of period N" dropdown, ask for the next valid
boundaries:

```php
$boundaries = Meteric::cancellationOptions($subscription, count: 3);
// list<CarbonImmutable>: the next 3 term ends that still satisfy the notice window
```

The package enforces the notice rule; rendering the choices is your UI's job. A
product with `cancel_notice_days` of 0 can cancel to any boundary.

### Minimum term

A minimum term commits a subscription for a number of periods before it may be
cancelled at all. `config['minimum_term_periods']` on the product sets it, and
the `minimum_term_periods` column on a price overrides it, so a product sold
monthly and yearly can commit each term differently. It is counted in periods,
not months: twelve periods of a quarterly price is three years.

```php
$product->config = ['minimum_term_periods' => 12];   // twelve periods, whichever price is taken
$yearly->minimum_term_periods = 1;                    // except this one
```

**The term is frozen onto the item at signup.** `subscribe()` and
`materializeLine()` write `minimum_term_periods` and `committed_until` onto the
`SubscriptionItem` from the price it was sold on, and nothing reads the catalog
again afterwards. Editing the product therefore changes what the next sale
commits to and never what an existing contract does, and an item created before
the columns existed carries `null` and is committed to nothing.

```php
Meteric::committedUntil($subscription);   // ?CarbonImmutable, the latest across active items
```

`cancellationOptions()` offers no boundary before that moment, and the notice
window is then measured against the boundaries that are on offer, so notice
attaches to the end of the term rather than to its start. Both read the **day**
the term ends rather than the instant: signup happened at some time of day, and
a caller cancelling to a date means midnight on it. `cancel()` to a
boundary inside the term throws `Meteric\Exceptions\WithinMinimumTerm`, whose
`earliest()` is the date that would have been allowed.

```php
Meteric::cancel($subscription, 'period_end');
// WithinMinimumTerm: ... The earliest date allowed is 2027-06-01.
```

**`cancel($sub, 'now')` is not subject to the term.** An immediate cancellation
is the provider ending the contract rather than the customer leaving it, which
is how a host terminates for non-payment, and that has to stay possible inside a
term.

A plan change inside the term never restarts, extends or clears it:
`committed_until` is left exactly as it was by `changePlan()` and by
`switchTerm()`. A change to a **cheaper** plan is refused with
`WithinMinimumTerm`, because settling part of the commitment away is the
cancellation the term forbids; a dearer or an equal one stands.
