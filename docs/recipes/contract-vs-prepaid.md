# Contract vs prepaid billing

Two billing shapes for the same engine. A prepaid product is paid up front and
stops the moment the customer stops paying. A contract product is committed for a
term: it keeps invoicing through the term and chases unpaid invoices instead of
stopping.

There is no contract/prepaid flag. The shape is the combination of three knobs:

- the downgrade policy on the product,
- the cancel timing you pass to `Meteric::cancel()`,
- how your event listeners react to an overdue invoice (pause vs keep billing).

## Prepaid

Pay up front. On non-payment your overdue listener pauses billing and you
suspend the service. A downgrade discards the unused value. Cancel is immediate.

Product config:

```php
use Meteric\Enums\DowngradePolicy;

$product->update([
    'config' => [
        'downgrade' => DowngradePolicy::Discard->value,
        'cancel_notice_days' => 0,
    ],
]);
```

Overdue listener:

```php
use Meteric\Events\InvoiceOverdue;
use Meteric\Facades\Meteric;

class SuspendPrepaid
{
    public function handle(InvoiceOverdue $event): void
    {
        foreach ($event->invoice->subscriptions() as $subscription) {
            Meteric::pause($subscription);              // billing stops, no further invoices
            $this->provisioner->suspend($subscription); // grace, then delete
        }
    }
}
```

`pause()` sets the subscription `paused`, and `meteric:run` accrues nothing for a
paused subscription. When the customer pays again, `resume($sub, $at)` flips it
back to `active` and starts a fresh cycle billed from `$at`. The paused gap is
forgiven. Cancel immediately:

```php
Meteric::cancel($subscription, 'now');
```

## Contract

Keep invoicing the whole term. `meteric:run` renews each cycle. An unpaid invoice
moves the subscription to `past_due`, which is still billable, so the term keeps
accruing while dunning runs. A downgrade defers to the next renewal. Cancel lands
on a term boundary, subject to a notice window.

Product config:

```php
use Meteric\Enums\DowngradePolicy;

$product->update([
    'config' => [
        'downgrade' => DowngradePolicy::Defer->value,
        'cancel_notice_days' => 30,
    ],
]);
```

Overdue listener:

```php
use Meteric\Events\InvoiceOverdue;

class DunContract
{
    public function handle(InvoiceOverdue $event): void
    {
        foreach ($event->invoice->subscriptions() as $subscription) {
            // No pause: the term keeps billing. Send a dunning notice instead.
            $this->dunning->notify($subscription, $event->invoice);
        }
    }
}
```

`Meteric::markOverdue()` (run via `meteric:mark-overdue`) sets `past_due` and
fires `InvoiceOverdue`. A `past_due` subscription stays billable, so `renew`
keeps accruing the term. Cancel at the current cycle end or a future boundary,
honouring the notice window:

```php
use Carbon\CarbonImmutable;

Meteric::cancel($subscription, 'period_end');
Meteric::cancel($subscription, CarbonImmutable::parse('2026-12-01'));
```

Scheduling a cancel to a boundary inside `cancel_notice_days` throws. Use
`Meteric::cancellationOptions($subscription)` to list the next valid boundaries.
See [Subscriptions](/usage/subscriptions) for the cancel mechanics.

## Side by side

| | Prepaid | Contract |
| --- | --- | --- |
| Downgrade policy | `DowngradePolicy::Discard` | `DowngradePolicy::Defer` |
| Notice window | `cancel_notice_days` 0 | `cancel_notice_days` > 0 |
| On overdue | `pause()` + suspend | keep billing, dun |
| Cancel timing | `'now'` | `'period_end'` or a boundary date |

The downgrade policy also takes `Credit` (credit the unused value on the next
invoice) and `Refund` (issue a credit note). See
[Upgrades and downgrades](/usage/plan-changes).
