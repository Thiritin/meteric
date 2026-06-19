# Addons and options

Addons and options are mid-cycle changes to a subscription item. Each one
prorates over the item's remaining period and produces its own itemized charge
or credit, so the invoice breakdown stays line by line.

## Addons

An addon is a bookable extra on an item, +4 GB RAM, an extra IP, a backup plan.

```php
use Billify\Facades\Billify;

$addon = Billify::addAddon($item, $ramPrice, qty: 1);
```

The addon's price is prorated over the item's remaining period and booked as a
`pending` charge of kind `addon`. The addon record links the item, product, and
price.

### Groups

Pass a `group` to make addons mutually exclusive within that group. Booking a
new addon in a group removes the active one first, with a prorated credit for its
unused portion.

```php
// Switch the backup tier: the old one in the "backup" group is credited out,
// the new one is charged, both prorated over the remaining period.
Billify::addAddon($item, $backupSilver, group: 'backup');
Billify::addAddon($item, $backupGold, group: 'backup');
```

This is how you model "pick one" upsells where the customer can change their
choice mid-cycle and the math stays clean.

## Options

An option is a configurable dimension on an item, gameserver slots, an OS
choice, a feature toggle. Options are keyed, so setting the same key again
updates it.

```php
use Billify\Facades\Billify;

$option = Billify::setOption(
    item: $item,
    key: 'slots',
    value: '32',
    type: 'quantity',
    price: $slotPrice,
    qty: 32,
);
```

| Option type | Use for |
|-------------|---------|
| `quantity` | Per-unit or tiered counts (slots, IPs). |
| `choice` | A dropdown or radio (location, OS). |
| `toggle` | A yes/no flag. |

When you pass a `price`, Billify prorates the *delta* against the previous
quantity. Raising slots from 16 to 32 charges the prorated 16-slot increase;
lowering it credits the prorated difference. With no price, the option is stored
without a charge, useful for `choice` and `toggle` settings that do not change
the bill. Read a toggle back with `$option->boolValue()`.

## Quantity

To change the base quantity of the item itself (not an option), use
`setQuantity()`. It prorates the difference like an option does.

```php
$item = Billify::setQuantity($item, 5); // from 3 → 5, prorated increase charged
```

Increasing quantity books a prorated charge; decreasing books a prorated credit.
The item's `quantity` is updated either way.

## When the change is billed

All of these write `pending` charges and credits with the item's current period
as their `covers` window. They appear on the next [invoice](/usage/invoicing)
for the account. Nothing is charged to a card here, Billify accrues; your
invoice driver and gateway settle.
