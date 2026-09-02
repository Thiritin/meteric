# Discounts

A discount is a standing reduction on a subscription item, spent one billed
period at a time. Prices cannot be negative, so this is the only place a
recurring reduction lives.

It is not a coupon. A code, who may redeem it, how often, and until when are the
host application's business; meteric takes the reduction the host resolved and
prices, freezes, bills and taxes it.

## What it looks like on an invoice

A discount raises a negative `Charge` of kind `discount` in the item's own line
group, so the invoice nests it under the line it reduces and the tax on that
period falls with it. The taxable base is the reduced one, per line, at the rate
that line carries.

## A discount at checkout

`OrderBuilder::discount()` attaches one to the line most recently added. It is
priced into the quote and frozen with the line, so the order total is already
net of it and the invoice the order raises agrees with what was collected.

```php
use Meteric\Facades\Meteric;
use Meteric\Pricing\DiscountSpec;

$order = Meteric::createOrder($customer)
    ->add($term, 1, label: 'web1', group: 'l0')
    ->discount(DiscountSpec::percent('20', 'WELCOME20', terms: 3))
    ->create();
```

`DiscountSpec` is percentage or fixed, against the line or against the setup
fee, for a number of billed periods or for the life of the item:

```php
DiscountSpec::percent('20', 'WELCOME20', terms: 3);                          // 20% off three periods
DiscountSpec::percent('100', 'FREEDOMAIN');                                  // free, for the life of the item
DiscountSpec::fixed(500, 'EUR', 'FIVEOFF', terms: 12);                       // €5 off twelve periods
DiscountSpec::percent('100', 'NOSETUP', DiscountTarget::Setup, terms: 1);    // the setup fee only
```

`terms` counts **billed periods, not calendar months**. A signup billed as one
first period spends one of them, and each renewal spends one more. A discount
with `terms: 1` therefore covers the checkout and nothing after it.

Several discounts on one line apply in the order they were added, each to what
is left after the one before it. A stack can take a line to zero and never
below: `create()` still refuses a negative order total.

## A discount on a live subscription

```php
$discount = Meteric::applyDiscount($item, DiscountSpec::percent('50', 'GOODWILL', terms: 2));
```

It takes effect from the **next** accrual. The period the item is in has already
been charged, and a charge that is on an invoice is not moved by adding a
discount behind it.

`Meteric::cancelDiscount($discount)` stops one before its terms are spent.
Periods already billed stay as billed.

## The regular price and the switchover date

A quote reports the discount without hiding what comes after it:

```php
$quote->toArray()['recurring'];
// [
//   'interval' => 'month',
//   'interval_count' => 1,
//   'total_minor' => 1000,                            // the regular price
//   'discount_minor' => 200,                          // off each period while it lasts
//   'discount_until' => '2026-09-01T00:00:00+00:00',  // null when it runs for the life of the item
//   'next_charge_at' => '2026-07-01T00:00:00+00:00',
// ]
```

`recurringTotal` stays the regular price throughout, so a checkout can show the
promotional price, the regular one and the date the second takes over. Some
jurisdictions require exactly that.

## The row

`meteric_discounts`: the subscription and item it sits on, the kind and its
value, the target, the label the invoice line carries, `terms_total`,
`terms_used` and a state.

| State | Meaning |
| --- | --- |
| `active` | still spending terms |
| `exhausted` | `terms_used` reached `terms_total` |
| `canceled` | stopped by hand |

`Discount::termsLeft()` reads the position; null means no limit.

A period that bills nothing raises no discount charge and spends no term, so a
free first period under `FreeUntilAnchor` does not eat one.
