# Quickstart

This walks the full path: a product with a price, a subscription, an invoice,
and a recorded payment. Every call goes through the `Meteric` facade.

## 1. Create a product and a price

```php
use Meteric\Models\{Product, Price};
use Meteric\Enums\{PricingModel, Interval, BillingMode, PricePurpose};

$product = Product::create([
    'type' => 'vps',
    'slug' => 'vps-xl',
    'name' => 'VPS XL',
    'pricing_model' => PricingModel::Fixed,
    'is_proratable' => true,
]);

$price = Price::create([
    'product_id' => $product->id,
    'currency' => 'EUR',
    'amount_minor' => 1000,            // €10.00
    'purpose' => PricePurpose::Recurring,
    'pricing_model' => PricingModel::Fixed,
    'interval' => Interval::Month,
    'interval_count' => 1,
    'billing_mode' => BillingMode::InAdvance,
]);
```

Money is stored in minor units. `amount_minor => 1000` is €10.00.

## 2. Subscribe a customer

```php
use Meteric\Facades\Meteric;

$subscription = Meteric::subscribe($user)
    ->add($price)
    ->create();
```

`subscribe($user)` resolves or creates a billing account for the model.
`create()` persists the subscription and its items and accrues the first cycle
as `pending` charges. The default first-period policy is `prorate_only`, so
the first charge covers the stub up to the anchor.

## 3. Issue the invoice

```php
$invoice = Meteric::invoicePending($subscription->account);
```

This collects the account's pending charges in one currency and hands them to
the bound invoice driver. On success the charges flip to `invoiced` and you get
the `Invoice` back. If the driver throws, the charges stay `pending`.

To subscribe and invoice in one step, use checkout:

```php
$result = Meteric::subscribe($user)
    ->add($price)
    ->checkout();

$result->subscription; // the created Subscription
$result->invoice;      // the issued Invoice (or null if nothing was due)
```

## 4. Record a payment

Your gateway tells you money arrived. Record it against the invoice.

```php
use Brick\Money\Money;

Meteric::recordPayment($invoice, Money::of('10.00', 'EUR'), 'pi_123');
```

The invoice moves to `partially_paid` or `paid` based on the running total.

## 5. Run the billing tick

One command closes due cycles. `meteric:run` rolls up elapsed usage, renews each
due subscription, invoices the affected accounts, enacts scheduled cancellations,
flags overdue invoices, and expires stale orders. Every step is idempotent, so
schedule it on a short interval.

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('meteric:run')->everyFiveMinutes();
```

The command acts only when a cycle has closed, so a frequent schedule keeps
billing prompt without redundant work. The underlying step is
`Meteric::renew($sub, $at)`, which accrues the next cycle for one subscription
and is idempotent on its own (the billing-period guard means a re-run over the
same window does nothing). From here, see [Subscriptions](/usage/subscriptions)
for trials and anchoring, and [Invoicing](/usage/invoicing) for the
charge-vs-invoice guarantee in detail.
