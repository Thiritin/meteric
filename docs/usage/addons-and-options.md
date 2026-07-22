# Addons and options

Addons and options are mid-cycle changes to a subscription item. Each one
prorates over the item's remaining period and produces its own itemized charge
or credit, so the invoice breakdown stays line by line.

## Addons

An addon is a bookable extra on an item, +4 GB RAM, an extra IP, a backup plan.

```php
use Meteric\Facades\Meteric;

$addon = Meteric::addAddon($item, $ramPrice, qty: 1);
```

The addon's price is prorated over the item's remaining period and booked as a
`pending` charge of kind `addon`. The addon record links the item, product, and
price. Like options, an active addon then recurs: every renewal bills it again
for the new period through the same price engine (tiers included).

### Groups

Pass a `group` to make addons mutually exclusive within that group. Booking a
new addon in a group removes the active one first, with a prorated credit for its
unused portion.

```php
// Switch the backup tier: the old one in the "backup" group is credited out,
// the new one is charged, both prorated over the remaining period.
Meteric::addAddon($item, $backupSilver, group: 'backup');
Meteric::addAddon($item, $backupGold, group: 'backup');
```

This is how you model "pick one" upsells where the customer can change their
choice mid-cycle and the math stays clean.

### Relative pricing

A relative addon charges a percentage of the owning item's base price. Give it a
`Price` with `pricing_model` of `Relative`, a `percent` (20 for 20%), and
`amount_minor` of 0. Backups at 20% of the base server:

```php
use Meteric\Enums\PricingModel;
use Meteric\Models\Price;

$backupsPrice = Price::create([
    'product_id' => $backupsProduct->id,
    'currency' => 'EUR',
    'pricing_model' => PricingModel::Relative,
    'percent' => 20,        // 20% of the base
    'amount_minor' => 0,
]);

$addon = Meteric::addAddon($item, $backupsPrice, group: 'backups');
```

The base is the owning item's `periodAmount()`, its current plan price times its
quantity, read at billing time. The percentage recomputes against the new base
after an upgrade or downgrade on the next cycle, with no manual step.

The addon's own quantity is ignored for a relative price: a percentage of the
base is one figure. Allowance, blocks, tiers, and caps do not apply either. A
flat setup fee on the price still does.

A relative addon needs a recurring base in the same currency. Booking one on a
usage or metered base, or with a currency that does not match the base, throws
`InvalidArgumentException`.

The invoice line description reads like "20% of VPS XL", and the line's unit
price shows the computed amount. `Meteric::removeAddon($addon)` credits the
prorated unused part, the same as any other addon.

## Options

An option is a configurable dimension on an item, gameserver slots, an OS
choice, a feature toggle. These are the WHMCS "configurable options", with the
same three shapes. Options are keyed, so setting the same key again updates it.

```php
use Meteric\Facades\Meteric;

$option = Meteric::setOption(
    item: $item,
    key: 'slots',
    value: '32',
    type: 'quantity',
    price: $slotPrice,
    qty: 32,
);
```

The `type` is a `Meteric\Enums\OptionType` value:

| `OptionType` | WHMCS equivalent | Use for |
|--------------|------------------|---------|
| `Quantity` (`quantity`) | Quantity | Per-unit counts you scale (slots, extra IPs). |
| `Choice` (`choice`) | Dropdown / radio | One priced choice of many (location, OS). |
| `Toggle` (`toggle`) | Yes/No | A single on/off flag. |

`setOption` has the full signature:

```php
setOption(
    SubscriptionItem $item,
    string $key,
    string $value,
    string $type,
    ?Price $price = null,
    float $qty = 1,
    ?CarbonImmutable $at = null,
    ?float $min = null,
    ?float $max = null,
    ?string $label = null,
): ItemOption
```

When you pass a `price`, Meteric prorates the *delta* against the previous
quantity for the current cycle. Raising slots from 16 to 32 charges the prorated
16-slot increase; lowering it credits the prorated difference. With no price, the
option is stored without a charge, useful for `choice` and `toggle` settings that
do not change the bill. Read a toggle back with `$option->boolValue()`.

`$min` and `$max` bound a quantity option. A `qty` below `$min` or above `$max`
throws `InvalidArgumentException` before anything is written.

### Options recur every renewal

An active option re-bills on every cycle, not once. When the accruer bills a
period, it bills each active option (and addon) for the same window: same
`covers`, `group`, and `title` as the base line. The option's line carries the
option key as its description, the price interval as its `unit`, and the period
in `covers`. Set 32 slots once and every renewal invoice carries a 32-slot line
for that period.

### Tiered and volume pricing

Because an option points at a `Price`, it inherits the price engine, including
[quantity tiers](/usage/products-and-prices#quantity-discounts-tiers). Give the
option's price a `pricing_model` of `Volume` or `Tiered` with a `tiers` table and
the quantity gets cheaper-as-it-grows pricing through `Price::amountFor`.

```php
$slotPrice = Price::create([
    'product_id' => $product->id,
    'currency' => 'EUR',
    'pricing_model' => PricingModel::Volume,
    'tiers' => [
        ['up_to' => 10,   'unit_minor' => 100], // 1 to 10 slots at €1
        ['up_to' => null, 'unit_minor' => 80],  // 11+ slots at €0.80
    ],
]);

Meteric::setOption($item, 'slots', '24', 'quantity', $slotPrice, qty: 24);
```

### Allowance, blocks and caps on options

Option and addon prices carry the same usage-style knobs as a
[meter dimension](/usage/usage-billing), so a flat per-unit option can hand out a
free allowance or bill in blocks without a tier table. They layer on top of the
flat/`unit_rate`/tier pricing through `Price::amountForQuantity`:

- `included_qty`: a free allowance subtracted before billing.
- `block_size`: bill per started block of N units (rounded up).
- `cap_minor`: clamp the charge to a maximum.
- `min_charge_minor`: floor the charge to a minimum.

A "backups" quantity option with 2 free, then €2.50 each:

```php
$backups = Price::create([
    'product_id' => $product->id,
    'currency' => 'EUR',
    'amount_minor' => 250,   // €2.50 per backup
    'included_qty' => 2,     // first 2 free
]);

Meteric::setOption($item, 'backups', '5', 'quantity', $backups, qty: 5);
// 5 backups bill (5 - 2) × €2.50 = €7.50
```

`block_size` bills per started block, so a 50 GB block at €4 charges €8 for 60 GB
(two blocks):

```php
$storage = Price::create([
    'product_id' => $product->id,
    'currency' => 'EUR',
    'amount_minor' => 400,   // €4 per block
    'block_size' => 50,      // per started 50 GB
]);
```

`billedUnits($qty)` exposes the post-allowance, post-block count these prices bill
on. Renewal charges for options and addons go through `amountForQuantity`, and
`setOption` prices a change as the difference of the two totals
(`amountForQuantity(new) minus amountForQuantity(old)`), which stays correct with
volume tiers and an allowance in play.

### Setup fee

If the option's price has a `setup_fee_minor`, a one-time `setup` line is charged
once, when the option is first added. Later quantity changes on the same option
do not re-charge it.

## Catalog options

`setOption` is the imperative path. A product can also *declare* its options up
front, so checkout reads a menu instead of hardcoding keys and prices.

`ProductOption` is a configurable option a product offers (`key`, `label`,
`type`, `required`, `min_qty`, `max_qty`, `sort`). Its `values()` are
`ProductOptionValue` rows (`value`, `label`, `price_id`), each pointing at a
`Price`, so per-term, tiered, and setup pricing come for free. `Product::options()`
lists them.

```php
use Meteric\Enums\OptionType;
use Meteric\Models\{Price, ProductOption, ProductOptionValue};

$ips = ProductOption::create([
    'product_id' => $product->id,
    'key' => 'extra_ips',
    'label' => 'Extra IPs',
    'type' => OptionType::Quantity,
    'min_qty' => 0,
    'max_qty' => 16,
]);

$ipv4 = ProductOptionValue::create([
    'option_id' => $ips->id,
    'value' => 'ipv4',
    'label' => 'IPv4 address',
    'price_id' => $ipv4Price->id,   // a Volume-tiered Price
]);
```

Apply a chosen value with `chooseOption`. It reads the key, type, bounds, and the
value's price off the catalog, then calls `setOption` for you:

```php
Meteric::chooseOption($item, $ipv4, qty: 8); // 8 IPv4 addresses, priced by tier
```

`chooseOption(SubscriptionItem $item, ProductOptionValue $value, float $qty = 1, ?CarbonImmutable $at = null)`.

## Displaying options in a form

The catalog and the option models render to JSON-ready arrays, so a checkout,
upgrade, or downgrade form is built from data, not hardcoded fields.

### Build an order or upgrade form

`Product::optionCatalog(float $qty = 1)` returns one entry per option, each
priced at `$qty`. JSON encode it straight to the frontend.

```php
$catalog = $product->optionCatalog();        // values priced at qty 1
$catalog = $product->optionCatalog(qty: 8);  // price quantity options at 8

return response()->json($catalog);
```

Each option entry carries `key`, `label`, `type`, `required`, `min`, `max`, and
`values[]`. Each value carries `value`, `label`, `amount_minor`, `amount`
(string), `currency`, `interval`, `pricing_model`, `included_qty`, `block_size`,
`tiers`, and `setup_fee_minor`, enough for the client to recompute the price as
the quantity changes. `ProductOption::toDisplay($qty)` and
`ProductOptionValue::toDisplay($qty)` produce the per-option and per-value rows
if you need them on their own; `ProductOptionValue::amountFor($qty)` returns the
`Money` for a value at a quantity.

### Show the current selection on a service page

Iterate the item's options and call `toDisplay()` on each, or `amount()` for the
`Money`. These are the per-period recurring costs.

```php
foreach ($item->options as $option) {
    $row = $option->toDisplay();
    // ['key' => 'slots', 'value' => '32', 'label' => '32 slots',
    //  'quantity' => 32.0, 'amount_minor' => 2560, 'amount' => '25.60',
    //  'currency' => 'EUR']

    $money = $option->amount(); // Brick\Money\Money, or null when free
}
```

### Upgrade / downgrade preview

Preview the new total with tax before committing, using a
[quote](/usage/quotes-and-checkout), then apply the change.

```php
$preview = Meteric::quote()
    ->tax(new TaxContext(countryCode: 'DE'))
    ->add($slotPrice, qty: 32)
    ->build();

// Show $preview->toArray(), then commit:
Meteric::chooseOption($item, $value, qty: 32);
// or the imperative path:
Meteric::setOption($item, 'slots', '32', 'quantity', $slotPrice, qty: 32);
```

## Quantity

To change the base quantity of the item itself (not an option), use
`setQuantity()`. It prorates the difference like an option does.

```php
$item = Meteric::setQuantity($item, 5); // from 3 → 5, prorated increase charged
```

Increasing quantity books a prorated charge; decreasing books a prorated credit.
The item's `quantity` is updated either way.

## When the change is billed

All of these write `pending` charges and credits with the item's current period
as their `covers` window. They appear on the next [invoice](/usage/invoicing)
for the account. Nothing is charged to a card here, Meteric accrues; your
invoice driver and gateway settle.

See also: [Build a web hosting company's billing](/recipes/web-hosting-company)
books addons and a volume-priced option, and
[Bill a gameserver per slot and per hour](/recipes/gameserver-slots) prices slots
as a configurable option.
