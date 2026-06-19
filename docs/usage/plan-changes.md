# Plan changes

`Meteric::changePlan()` switches a subscription item to a new price. The
direction is detected from the money: a higher full-period amount is an upgrade,
a lower one is a downgrade. The two go down different paths.

```php
use Meteric\Facades\Meteric;

$item = Meteric::changePlan($item, $newPrice);
```

## Upgrades are prorated now

On an upgrade, Meteric charges the difference for the rest of the current period
immediately. It does this with two itemized charges:

- A credit for the unused portion of the old plan (prorated from now to period
  end).
- A prorated charge for the new plan over the same remaining window.

```php
// €10 → €20 plan, halfway through the month:
// credit ~€5 unused old, charge ~€10 prorated new → ~€5 net due now.
$item = Meteric::changePlan($item, $biggerPrice);
```

Both lines land as `pending` charges on the account, so they show up on the next
invoice. The item moves to the new price and product right away.

## Downgrades never move money mid-cycle

A downgrade does not refund, credit, or extend. It only differs on *when* the
cheaper plan takes effect. The policy decides that.

```php
use Meteric\Enums\DowngradePolicy;

// Keep the current tier until the paid period ends, then renew lower.
Meteric::changePlan($item, $smallerPrice, DowngradePolicy::Defer);

// Switch to the lower plan now. Unused value of the higher plan is forfeited.
Meteric::changePlan($item, $smallerPrice, DowngradePolicy::Discard);
```

| Policy | Effect |
|--------|--------|
| `Defer` (default) | The change is stored as a pending change. At the next renewal the item swaps to the lower price. The customer keeps the higher tier until the period they already paid for ends. |
| `Discard` | The item swaps to the lower price immediately. The unused value of the higher plan is forfeited, no credit, no refund. |

When you do not pass a policy, Meteric uses the product's policy
(`config['downgrade']`), which itself defaults to `defer`. The deferred change
is applied during [renewal](/usage/subscriptions#renew); you can see it pending
on the item:

```php
$item->hasPendingChange(); // true while a deferred downgrade is queued
$item->pending_change;     // ['price_id' => ..., 'apply_at' => ...]
```

## Hourly and metered plans

Usage-based plans bill in arrears at the real rate. There is no prepaid value to
prorate or forfeit, so a change of an hourly or metered price takes effect going
forward: usage before the change bills at the old rate, usage after bills at the
new one. Roll up the old window before switching the rate if you want a clean
cutover. See [Usage billing](/usage/usage-billing).
