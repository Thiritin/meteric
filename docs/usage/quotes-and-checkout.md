# Quotes and checkout

A quote renders a "due now + recurring" breakdown for a checkout page without
persisting anything. It runs the same planner, prorator, and tax stack as real
billing, so the quote matches the invoice that gets issued.

## Building a quote

```php
use Meteric\Facades\Meteric;
use Meteric\Enums\{AnchorMode, FirstPeriodPolicy};
use Meteric\Tax\TaxContext;
use Carbon\CarbonImmutable;

$quote = Meteric::quote()
    ->anchor(AnchorMode::FixedDay, 1)                  // align to the 1st
    ->firstPeriod(FirstPeriodPolicy::ProratePlusFull)  // stub + first full month
    ->tax(new TaxContext(countryCode: 'DE'))
    ->at(CarbonImmutable::now())                        // deterministic
    ->add($vpsPrice, qty: 1, label: 'VPS XL')
    ->build();
```

`build()` returns a read-only `Quote`. Nothing is written to the database.

## Serializing for the frontend

```php
return $quote->toArray(); // or $quote->toJson()
```

`toArray()` produces a stable shape:

```php
[
    'currency' => 'EUR',
    'due_now' => [
        'subtotal_minor' => 1200,
        'tax_minor' => 228,
        'total_minor' => 1428,
    ],
    'recurring' => [
        'interval' => 'month',
        'interval_count' => 1,
        'total_minor' => 1000,
        'next_charge_at' => '2026-07-01T00:00:00+00:00',
    ],
    'lines' => [/* each line: label, kind, quantity, covers, unit_rate, amount_minor, tax_minor, estimated */],
    'estimated' => false,
]
```

Order a €10/month plan on the 25th with `prorate_plus_full` and the due-now lines
are a 6-day stub (€2.00) and the first full month (€10.00); recurring is €10/month
from the 1st.

## Estimated lines

Usage and hourly prices bill in arrears, so their amount is unknown at checkout.
A quote includes them as lines flagged `estimated` with the unit rate and a zero
amount, and the quote's top-level `estimated` flag is set. Show them as "metered,
billed monthly" rather than a fixed price.

## Checkout

Checkout is subscribe then immediately invoice. Use the subscription builder and
end with `checkout()` instead of `create()`.

```php
use Meteric\Facades\Meteric;

$result = Meteric::subscribe($user)
    ->add($vpsPrice, qty: 1)
    ->anchor(\Meteric\Enums\AnchorMode::FixedDay, 1)
    ->firstPeriod(\Meteric\Enums\FirstPeriodPolicy::ProratePlusFull)
    ->checkout();

$result->subscription;  // created Subscription
$result->invoice;       // Invoice billed now, or null if nothing was due
```

`checkout()` returns a `Checkout` with the created subscription and the invoice
that was issued for the charges due now. Because the quote and the real flow
share the same calculators, the invoice total matches what you quoted for the
same inputs.
