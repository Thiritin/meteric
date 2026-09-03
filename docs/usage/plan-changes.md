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
| `Prorate` (default) | Credit the unused portion of the old plan, charge the new plan prorated over the rest of the cycle. The item moves to the new price right away. |
| `Defer` | Swap at the next renewal. Keep the current plan until then. No money moves mid-cycle. |

The default prorated upgrade settles the difference for the rest of the period
with two itemized charges:

```php
// €10 → €20 plan, halfway through the month:
// credit ~€5 unused old, charge ~€10 prorated new → ~€5 net due now.
$item = Meteric::changePlan($item, $biggerPrice);
```

`Defer` swaps at the next renewal, keeping the current plan until then:

```php
use Meteric\Enums\UpgradePolicy;

Meteric::changePlan($item, $biggerPrice, upgrade: UpgradePolicy::Defer);
```

## Downgrades

`DowngradePolicy` (`Meteric\Enums\DowngradePolicy`) controls when the cheaper
plan takes effect and what happens to the unused value of the higher plan.

| Case | Effect |
|------|--------|
| `Defer` (default for contracts) | Keep the current tier until the paid period ends, then renew lower. The change is stored as a pending change and applied at renewal. |
| `Discard` | Swap to the lower plan immediately. The unused value of the higher plan is forfeited, no credit, no refund. |
| `Credit` | Swap immediately and settle the rest of the cycle as one pending line: the unused old value less the new plan's prorated remainder, a negative `credit` that lands on the next invoice. No money moves, no document. |
| `Refund` | Swap immediately and issue a credit note for the net of the rest of the cycle (the unused old value less the new plan's prorated remainder) against the invoice that billed the current period. A `CreditNoteIssued` listener in your app performs the actual refund. |

```php
use Meteric\Enums\DowngradePolicy;

// Keep the current tier until the paid period ends, then renew lower.
Meteric::changePlan($item, $smallerPrice, DowngradePolicy::Defer);

// Switch immediately, credit the unused value of the higher plan.
Meteric::changePlan($item, $smallerPrice, DowngradePolicy::Credit);

// Switch immediately, issue a credit note for the unused value.
Meteric::changePlan($item, $smallerPrice, DowngradePolicy::Refund);
```

`Credit` and `Refund` are the mirror of a prorated upgrade: the customer is on
the cheaper plan for the rest of the cycle and pays for it. Downgrade a €30 plan
to €10 halfway through the month and the figure is €15 unused less €5 owed, €10
net, rounded once: `Credit` writes it as one pending `credit` line, `Refund`
issues a credit note for it. Equal prices write nothing. The next renewal bills
the new plan in full.

`Credit` and `Refund` differ in where the value goes. `Credit` writes a pending
negative charge that reduces a later invoice: no money moves and no document is
produced. `Refund` issues a credit note against the invoice that billed the
current period, and a `CreditNoteIssued` listener in your app moves the money
through your gateway or your own credit system. With nothing invoiced yet there
is no invoice to credit, so `Refund` falls back to a pending credit line like
`Credit`.

Refunds and any credit-balance or Guthaben handling live in your app, driven by
events and hooks. This package issues the documents and fires the events; it does
not return money or track a credit balance.

When you do not pass a downgrade policy, Meteric uses the product's policy
(`config['downgrade']`), which itself defaults to `Defer`. The deferred change is
applied during [renewal](/usage/subscriptions#renew); you can see it pending on
the item:

```php
$item->hasPendingChange(); // true while a deferred change is queued
$item->pending_change;     // ['price_id' => ..., 'apply_at' => ...]
```

## Term changes

`changePlan()` prorates inside the period it is in and leaves the term alone. A
monthly customer moving to the yearly price mid-month would get the yearly
amount prorated over the rest of their month, on a period that stays monthly:
a price change, not a cycle change.

`Meteric::switchTerm()` is the cycle change. It settles the running period and
opens a new one from the switch instant on the new price's term:

```php
use Meteric\Facades\Meteric;

$item = Meteric::switchTerm($item, $yearlyPrice);
```

Three things happen, in this order:

1. **The closing window's usage is rolled up.** Metered usage recorded between
   the period start and the switch is rated and billed with the period it
   belongs to, rather than carried into the new one where it would bill against
   a window it did not happen in.
2. **What the closing window was billed and will not deliver comes back**, as one
   `Unused <plan>` credit. The figure is the unused fraction of everything the
   window billed: the period, its options, its addons and its discounts. It is
   the whole billed value rather than the plan price alone because the new
   period bills all of them again from the switch instant.
3. **The new period is accrued whole** on the new term, `[at, at + term)`, with
   its options, addons and discounts.

Every figure is a pending charge, so nothing is invoiced until you invoice it:

```php
Meteric::switchTerm($item, $yearlyPrice);
Meteric::invoicePending($item->subscription->account);
```

The window already billed keeps its guard, shortened to end at the switch, so
the period opening inside it still bills. A deferred plan change waiting on the
item is dropped: the switch happens now and supersedes it.

Preview it before a person confirms:

```php
$preview = Meteric::previewTermSwitch($item, $yearlyPrice);

$preview->closing;      // Period the running period is cut to
$preview->opening;      // Period that opens on the new term
$preview->unused;       // Money, the credit for the unused remainder
$preview->usage;        // Money, the closing window's rated usage
$preview->usageLines;   // one entry per dimension: key, used, unit, amount_minor
$preview->recurring;    // Money, the new period billed whole
$preview->total();      // recurring + usage - unused
$preview->toArray();
```

`previewTermSwitch()` writes nothing, spends no discount term, and runs the same
guards. Both throw `Meteric\Exceptions\TermNotSwitchable` when the item is not
active, has no period, is not moving between recurring prices, when the instant
is outside the running period, or when a window inside the period that would
open has already been billed.

Usage rating, the unused credit and the new period's amount are the same
calculations the switch then writes, so a preview shown to a customer and the
invoice they get cannot disagree.

## Charges, not credit notes

Every proration credit and charge is a `pending` charge on the account. They
appear on the next invoice. Under every policy except `Refund`, `changePlan()`
issues no invoice and no credit-note document on its own. `Refund` is the one
exception: it issues a credit note against the invoice that billed the current
period and fires `CreditNoteIssued`. See [Credit notes and
refunds](/usage/invoicing#credit-notes-and-refunds).

## Hourly and metered plans

Usage-based plans bill in arrears at the real rate. There is no prepaid value to
prorate or forfeit, so `changePlan()` ignores the policies for any in-arrears
item: the change is rate-forward. Usage before the change bills at the old rate,
usage after bills at the new one. Roll up the old window before switching the
rate if you want a clean cutover. See [Usage billing](/usage/usage-billing).

See also: [Build a web hosting company's billing](/recipes/web-hosting-company)
walks an upgrade and a downgrade through to invoicing.
