# Plan changes

`Meteric::changePlan()` switches a subscription item to a new price. The
direction is detected from the money: a higher full-period amount is an upgrade,
a lower one is a downgrade. Each direction takes its own policy.

```php
use Meteric\Facades\Meteric;

$item = Meteric::changePlan($item, $newPrice);
```

The full signature:

```php
changePlan(
    SubscriptionItem $item,
    Price $newPrice,
    ?DowngradePolicy $downgrade = null,
    ?UpgradePolicy $upgrade = null,
    ?CarbonImmutable $at = null,
): SubscriptionItem
```

Pass the policy for the direction the change goes. An upgrade reads `$upgrade`, a
downgrade reads `$downgrade`. The other argument is ignored.

## Upgrades

`UpgradePolicy` (`Meteric\Enums\UpgradePolicy`) controls when the swap happens
and how it is billed.

| Case | Effect |
|------|--------|
| `ProrateNow` (default) | Credit the unused portion of the old plan, charge the new plan prorated over the rest of the cycle. The item moves to the new price right away. |
| `Defer` | Swap at the next renewal. Keep the current plan until then. No money moves mid-cycle. |
| `FullNow` | Swap immediately and charge the full new plan with no proration. |

The default prorated upgrade settles the difference for the rest of the period
with two itemized charges:

```php
// €10 → €20 plan, halfway through the month:
// credit ~€5 unused old, charge ~€10 prorated new → ~€5 net due now.
$item = Meteric::changePlan($item, $biggerPrice);
```

`FullNow` charges the whole new plan instead of prorating:

```php
use Meteric\Enums\UpgradePolicy;

// Swap to the bigger plan and charge its full period amount.
Meteric::changePlan($item, $biggerPrice, upgrade: UpgradePolicy::FullNow);
```

## Downgrades

`DowngradePolicy` (`Meteric\Enums\DowngradePolicy`) controls when the cheaper
plan takes effect and what happens to the unused value of the higher plan.

| Case | Effect |
|------|--------|
| `Defer` (default for contracts) | Keep the current tier until the paid period ends, then renew lower. The change is stored as a pending change and applied at renewal. |
| `Discard` | Swap to the lower plan immediately. The unused value of the higher plan is forfeited, no credit, no refund. |
| `Credit` | Swap immediately and credit the unused old value as a pending negative charge that lands on the next invoice. |

```php
use Meteric\Enums\DowngradePolicy;

// Keep the current tier until the paid period ends, then renew lower.
Meteric::changePlan($item, $smallerPrice, DowngradePolicy::Defer);

// Switch immediately, credit the unused value of the higher plan.
Meteric::changePlan($item, $smallerPrice, DowngradePolicy::Credit);
```

When you do not pass a downgrade policy, Meteric uses the product's policy
(`config['downgrade']`), which itself defaults to `Defer`. The deferred change is
applied during [renewal](/usage/subscriptions#renew); you can see it pending on
the item:

```php
$item->hasPendingChange(); // true while a deferred change is queued
$item->pending_change;     // ['price_id' => ..., 'apply_at' => ...]
```

## Charges, not credit notes

Every proration credit and charge is a `pending` charge on the account. They
appear on the next invoice. `changePlan()` issues no invoice and no credit-note
document on its own. A credit note is only for reversing an invoice that is
already issued or paid. See [Credit notes and
refunds](/usage/invoicing#credit-notes-and-refunds).

## Hourly and metered plans

Usage-based plans bill in arrears at the real rate. There is no prepaid value to
prorate or forfeit, so a change of an hourly or metered price takes effect
immediately: usage before the change bills at the old rate, usage after bills at the
new one. Roll up the old window before switching the rate if you want a clean
cutover. See [Usage billing](/usage/usage-billing).

See also: [Build a web hosting company's billing](/recipes/web-hosting-company)
walks an upgrade and a downgrade through to invoicing.
