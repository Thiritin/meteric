# Commitments

A commitment locks a subscription item into a term in exchange for a reduced
rate, with an optional upfront payment and an early-termination fee. This is the
AWS Reserved Instance / WHMCS contract-term pattern: the customer commits to a
year, pays some upfront, and gets a cheaper recurring rate for the term.

## Adding a commitment

```php
use Meteric\Facades\Meteric;
use Meteric\Enums\Interval;
use Brick\Money\Money;

$commitment = Meteric::commit(
    item: $item,
    termInterval: Interval::Month,
    termCount: 12,
    upfront: Money::of('120.00', 'EUR'),
    rate: Money::of('8.00', 'EUR'),     // committed per-period rate, below list
    earlyTerm: ['remaining_pct' => 0.5],
);
```

This creates an active `Commitment` covering the term and, if the upfront is
positive, books a one-off `pending` charge for it. While the commitment is
active, the accruer bills the committed `rate` instead of the item's list price:
the item's `periodAmount()` returns `committedRate × quantity` for an active
commitment, then renewals charge that.

## The committed rate during renewals

You do nothing special at renewal time. `Meteric::renew()` reads the active
commitment and accrues the committed rate. When the term ends and the commitment
expires, renewals fall back to the price's amount.

```php
$item->periodAmount();        // committed rate × qty while active, else list price
$commitment->isActive();      // bool
$commitment->committedRate(); // Money
```

## Early termination

```php
$fee = Meteric::terminateCommitment($commitment);
```

Terminating early closes the commitment and bills the configured fee as a
one-off charge. The fee is set on `earlyTerm` when you commit, in one of two
shapes:

| `earlyTerm` rule | Fee |
|------------------|-----|
| `['fee_minor' => 5000]` | A flat €50.00. |
| `['remaining_pct' => 0.5]` | A percentage of the remaining committed value (whole periods left × committed rate). |

With `remaining_pct`, Meteric counts the whole periods left in the term and
multiplies remaining-value by the percentage. `terminateCommitment()` returns the
`Money` fee it charged (zero when no rule applies). Like every other charge, the
fee accrues as `pending` and bills on the next [invoice](/usage/invoicing).
