# Build a web hosting company's billing

A worked example: a hosting company sells three webhosting plans, a domain
registration, two bookable addons, and extra IPs as a configurable option. This
walks the whole path from catalog to invoice, plus upgrades, downgrades, and
suspend-on-overdue.

The concepts behind each step live in
[Products and prices](/usage/products-and-prices),
[Subscriptions](/usage/subscriptions),
[Addons and options](/usage/addons-and-options),
[Plan changes](/usage/plan-changes), and [Invoicing](/usage/invoicing). This page
stitches them together.

## The catalog

### Webhosting plans

Three tiers, each a `Product` with one recurring monthly `Price`. The price
carries a setup fee in `setup_fee_minor`, billed once when the item is first
accrued.

```php
use Meteric\Models\{Product, Price};
use Meteric\Enums\{PricingModel, PricePurpose, Interval, BillingMode};

function plan(string $slug, string $name, int $monthly, int $setup): Price
{
    $product = Product::create([
        'type' => 'webhosting',
        'slug' => $slug,
        'name' => $name,
        'pricing_model' => PricingModel::Fixed,
        'is_proratable' => true,
        'config' => ['downgrade' => 'defer'],
    ]);

    return Price::create([
        'product_id' => $product->id,
        'currency' => 'EUR',
        'amount_minor' => $monthly,
        'setup_fee_minor' => $setup,
        'purpose' => PricePurpose::Recurring,
        'pricing_model' => PricingModel::Fixed,
        'interval' => Interval::Month,
        'interval_count' => 1,
        'billing_mode' => BillingMode::InAdvance,
    ]);
}

$starter  = plan('hosting-starter',  'Starter',  500,  1000); // €5/mo, €10 setup
$pro      = plan('hosting-pro',      'Pro',      1200, 1000); // €12/mo, €10 setup
$business = plan('hosting-business', 'Business', 2500, 0);    // €25/mo, no setup
```

`is_proratable` lets upgrades, downgrades, and addons prorate over the remaining
period. `config['downgrade']` sets the default downgrade policy for the product.

### Domain registration

A domain is a one-off yearly charge, not a recurring subscription. The same
product carries two prices keyed by `purpose`: one to register, one to renew.

```php
$domain = Product::create([
    'type' => 'domain',
    'slug' => 'domain-com',
    'name' => '.com domain',
    'pricing_model' => PricingModel::OneOff,
    'is_proratable' => false,
]);

Price::create([
    'product_id' => $domain->id,
    'currency' => 'EUR',
    'amount_minor' => 1200,                  // €12.00 to register
    'purpose' => PricePurpose::Register,
    'pricing_model' => PricingModel::OneOff,
]);

Price::create([
    'product_id' => $domain->id,
    'currency' => 'EUR',
    'amount_minor' => 1500,                  // €15.00 to renew
    'purpose' => PricePurpose::Renew,
    'pricing_model' => PricingModel::OneOff,
]);
```

A one-off price has no `interval`, so `isRecurring()` is false. Adding it to a
subscription books a single immediate charge instead of starting a cycle. Pull
the right price by purpose at checkout:

```php
$register = $domain->priceFor('EUR', PricePurpose::Register);
$renew    = $domain->priceFor('EUR', PricePurpose::Renew);
```

### Addons: extra storage and mailboxes

Addons are bookable extras on a hosting item. Each is a product with an `addon`
price.

```php
function addon(string $slug, string $name, int $monthly): Price
{
    $product = Product::create([
        'type' => 'addon',
        'slug' => $slug,
        'name' => $name,
        'pricing_model' => PricingModel::Fixed,
        'is_proratable' => true,
    ]);

    return Price::create([
        'product_id' => $product->id,
        'currency' => 'EUR',
        'amount_minor' => $monthly,
        'purpose' => PricePurpose::Addon,
        'pricing_model' => PricingModel::Fixed,
        'interval' => Interval::Month,
        'interval_count' => 1,
        'billing_mode' => BillingMode::InAdvance,
    ]);
}

$extraStorage  = addon('addon-storage-10gb', '+10 GB storage', 200); // €2/mo
$extraMailboxes = addon('addon-mailboxes-5',  '+5 mailboxes',   150); // €1.50/mo
```

### Configurable option: extra IPs with a volume discount

Extra IPs are a quantity option priced with a volume discount: the more you
buy, the cheaper each one. Use `PricingModel::Volume` and a `tiers` table. A
tier is `['up_to' => int|null, 'unit_minor' => int]`, ordered low to high, with
`up_to: null` as the last unbounded tier.

```php
$ipProduct = Product::create([
    'type' => 'option',
    'slug' => 'option-extra-ip',
    'name' => 'Extra IPv4',
    'pricing_model' => PricingModel::Volume,
    'is_proratable' => true,
]);

$ipPrice = Price::create([
    'product_id' => $ipProduct->id,
    'currency' => 'EUR',
    'purpose' => PricePurpose::Option,
    'pricing_model' => PricingModel::Volume,
    'tiers' => [
        ['up_to' => 2,    'unit_minor' => 200], // 1 to 2 IPs at €2 each
        ['up_to' => 8,    'unit_minor' => 150], // 3 to 8 IPs at €1.50 each
        ['up_to' => null, 'unit_minor' => 100], // 9+ IPs at €1 each
    ],
]);
```

With `Volume`, the whole quantity is priced at the tier it lands in: 5 IPs bill
`5 × €1.50 = €7.50`. Check it with `amountFor()`:

```php
$ipPrice->amountFor(5);   // Money €7.50
$ipPrice->amountFor(10);  // Money €10.00 (10 × €1.00)
```

## Subscribe a customer

Open a subscription with a Starter plan and the extra-storage addon. The
subscription bills in advance, so `create()` accrues the first cycle as pending
charges. Attach the addon after the item exists.

```php
use Meteric\Facades\Meteric;

$subscription = Meteric::subscribe($user)
    ->add($starter, qty: 1, resource: $hostingAccount)
    ->create();

$item = $subscription->items()->first();

Meteric::addAddon($item, $extraStorage, qty: 1);
```

Set the IP option on the same item. `setOption()` prorates the option's price
over the item's remaining period:

```php
Meteric::setOption(
    item: $item,
    key: 'extra_ips',
    value: '3',
    type: 'quantity',
    price: $ipPrice,
    qty: 3,
);
```

At this point the account holds several pending charges: the Starter setup fee,
the first prorated (or full) month of Starter, the prorated addon, and the
prorated IP option. None of them are a document yet.

## Register the domain on the same account

The domain is a one-off purchase. Add it to a throwaway subscription, or add it
as a second item alongside hosting. Either way it books one immediate charge:

```php
$register = $domain->priceFor('EUR', \Meteric\Enums\PricePurpose::Register);

Meteric::subscribe($user)
    ->add($register, qty: 1, resource: $domainRecord)
    ->create();
```

The register charge lands `pending` on the same billing account, so it bills on
the next invoice with everything else.

## Issue the first invoice

`invoicePending()` collects the account's pending charges in one currency and
hands them to the invoice driver. On driver success the charges flip from
`pending` to `invoiced`; on failure nothing flips and the charges wait for the
next run.

```php
use Meteric\Models\BillingAccount;

$account = BillingAccount::firstOrCreate(
    ['owner_type' => $user->getMorphClass(), 'owner_id' => $user->getKey()],
    ['currency' => 'EUR'],
);

$invoice = Meteric::invoicePending($account);

$invoice->total();        // Money, the first bill
$invoice->lines;          // itemized: plan, setup, addon, option, domain
```

If you want subscribe-then-invoice in one call, use the checkout builder:

```php
$checkout = Meteric::subscribe($user)
    ->add($starter, qty: 1, resource: $hostingAccount)
    ->checkout();

$checkout->invoice; // the issued Invoice (null if nothing was pending)
```

Record payment when your gateway confirms it:

```php
use Brick\Money\Money;

Meteric::recordPayment($invoice, $invoice->total(), 'pi_123');
```

## The monthly renewal loop

`meteric:run` closes due cycles. For each subscription whose period has ended it
rolls up elapsed usage, renews the next month, and invoices the account, then
enacts scheduled cancellations, flags overdue invoices, and expires stale orders.
Every step is idempotent, so schedule it on a short interval.

```php
// routes/console.php
use Illuminate\Support\Facades\Schedule;

Schedule::command('meteric:run')->everyFiveMinutes();
```

The underlying renewal step is `Meteric::renew($sub, $at)`, which accrues the next
cycle for one subscription's due items and returns the charges it created (empty
when nothing was due). Addons and options ride on the item's cycle, so they renew
with it. A deferred downgrade queued on an item is applied at the period boundary
during renewal.

## Upgrade Starter to Pro (prorated)

`changePlan()` detects the direction from the full-period amount. Pro (€12) is
higher than Starter (€5), so this is an upgrade and Meteric charges the
difference for the rest of the current period: a credit for the unused
Starter, a prorated charge for Pro.

```php
$item = Meteric::changePlan($item, $pro);
```

Both lines land as `pending` charges. The item switches to the Pro price and
product immediately, so the next renewal bills Pro in full. The two prorated
lines bill on the next `invoicePending()` run.

## Downgrade Pro to Starter (defer or discard)

A downgrade never moves money mid-cycle. It only differs on *when* the cheaper
plan takes effect.

```php
use Meteric\Enums\DowngradePolicy;

// Keep Pro until the paid period ends, then renew on Starter.
Meteric::changePlan($item, $starter, DowngradePolicy::Defer);

// Drop to Starter immediately. Unused Pro value is forfeited, no credit.
Meteric::changePlan($item, $starter, DowngradePolicy::Discard);
```

With `Defer` (the default, and what `config['downgrade'] => 'defer'` set on the
products), the change is stored as a pending change and applied at the next
renewal. You can see it queued:

```php
$item->hasPendingChange();  // true while the deferred downgrade waits
$item->pending_change;      // ['price_id' => ..., 'apply_at' => ...]
```

Pass no policy and Meteric uses the product's `config['downgrade']`.

## Suspend on overdue

`meteric:run` flags issued, unpaid invoices past `due_at`, moves their
subscriptions to `past_due`, and fires `InvoiceOverdue` and `SubscriptionPastDue`,
so the five-minute tick already catches overdue invoices. Run `meteric:mark-overdue`
on its own when you want the overdue scan without the rest of the tick.

```php
// routes/console.php
Schedule::command('meteric:mark-overdue')->daily();
```

Listen for `InvoiceOverdue` and suspend prepaid hosting. `pause()` stops billing
(`renew()` accrues nothing while paused); you do the provisioning half.

```php
use Meteric\Events\InvoiceOverdue;
use Meteric\Facades\Meteric;

class SuspendOverdueHosting
{
    public function handle(InvoiceOverdue $event): void
    {
        foreach ($event->invoice->billedSubscriptions() as $subscription) {
            Meteric::pause($subscription);
            $this->provisioner->suspend($subscription); // stop the hosting account
        }
    }
}
```

Resume when the invoice is paid. `Invoice::billedSubscriptions()` gives you the set the
invoice covered:

```php
use Meteric\Events\InvoicePaid;
use Meteric\Enums\SubscriptionState;

class ResumeOnPayment
{
    public function handle(InvoicePaid $event): void
    {
        foreach ($event->invoice->billedSubscriptions() as $subscription) {
            if ($subscription->state === SubscriptionState::Paused) {
                Meteric::resume($subscription);
                $this->provisioner->start($subscription);
            }
        }
    }
}
```

`resume()` starts a fresh cycle from the resume date, so the customer is not
back-billed for the suspended gap. The full event list is in
[Events and hooks](/usage/extending).

## What the invoice looks like

A first invoice for a Pro plan with a setup fee, a storage addon, and a domain
registration. With the [Lexware Office driver](/usage/invoicing#lexware-office-lexoffice)
the line title becomes the lexoffice `name`, the multi-line description stays the
description, `unit` becomes `unitName`, the amounts post as **net** with a tax
percentage, and lexoffice computes the gross. The numbers below use 19% German
VAT. The billed cycle posts as a service period spanning the invoice, with an
inclusive end date (June is `2026-06-01 to 2026-06-30`, not `to 2026-07-01`).

Pass `group:` on a line to split the invoice into sections. Each distinct group
posts a lexoffice `type:"text"` heading row above its lines:

```php
Meteric::subscribe($user)
    ->add($pro, qty: 1, resource: $hostingAccount, group: 'Hosting')
    ->add($register, qty: 1, resource: $domainRecord, label: 'example.com', group: 'Domains')
    ->create();
```

| Item | Detail | Qty | Unit | Net | VAT | Gross |
|------|--------|-----|------|-----|-----|-------|
| **Hosting** | | | | | | |
| Webhosting Pro - alice.example | 2026-06-01 to 2026-06-30 | 1 | month | €9.99 | €1.90 | €11.89 |
| Webhosting Pro - alice.example | Setup fee | 1 | | €5.00 | €0.95 | €5.95 |
| Extra storage - alice.example | 2026-06-01 to 2026-06-30 | 1 | month | €2.00 | €0.38 | €2.38 |
| **Domains** | | | | | | |
| example.com | Registration | 1 | year | €12.00 | €2.28 | €14.28 |
| **Subtotal (net)** | | | | **€28.99** | | |
| **VAT (19%)** | | | | | **€5.51** | |
| **Total (gross)** | | | | | | **€34.50** |

`line->title` is the lexoffice `name`, `line->description` holds the detail (the
period, on its own line), `line->unit` is the `unitName`, and
`line->coversLabel()` formats the inclusive service period. Tax is added per line
by the [tax resolver](/usage/tax).
