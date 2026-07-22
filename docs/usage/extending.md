# Extending: events and hooks

Meteric never touches your infrastructure. It computes billing and fires events
at the moments that matter. You listen and do the real work: start and stop VMs
and gameservers, send mail, sync an accounting system. This page lists the events
and shows the suspend-on-overdue and resume-on-payment flow.

## Events

All live in `Meteric\Events`. Register listeners the normal Laravel way.

| Event | Fired when | Carries |
|-------|-----------|---------|
| `InvoiceIssued` | an invoice is issued | `Invoice` |
| `InvoicePaid` | an invoice is paid in full | `Invoice`, `Payment` |
| `InvoicePartiallyPaid` | a part payment lands | `Invoice`, `Payment` |
| `InvoiceVoided` | an unpaid invoice is voided | `Invoice` |
| `CreditNoteIssued` | a credit note is issued (the refund hook) | `CreditNote` |
| `InvoiceOverdue` | `meteric:mark-overdue` finds it past due | `Invoice` |
| `SubscriptionPastDue` | an overdue invoice covers the subscription | `Subscription`, `Invoice` |
| `SubscriptionRenewed` | a renewal accrued charges | `Subscription`, `Charge[]` |
| `SubscriptionPaused` | billing was suspended | `Subscription` |
| `SubscriptionResumed` | billing resumed | `Subscription` |
| `SubscriptionCancellationScheduled` | a future cancel was set (notice/confirmation) | `Subscription`, `CarbonImmutable $at`, `array $meta` |
| `SubscriptionCanceled` | a subscription was terminated | `Subscription` |
| `OrderCreated` | a pending order was placed | `Order` |
| `OrderPaid` | an order was paid and materialized | `Order`, `?Invoice`, `?Payment` |
| `SubscriptionStarted` | a paid order became a subscription (the provisioning hook) | `Order`, `Subscription`, `?Invoice` |
| `OrderCanceled` | a pending order was canceled | `Order` |
| `OrderExpired` | a pending order passed its TTL | `Order` |

## Suspension

Suspending is a billing decision plus a provisioning action. Meteric owns the
billing half through `pause()` and `resume()`. You own the provisioning half in a
listener.

```php
use Meteric\Facades\Meteric;

Meteric::pause($subscription);   // state -> paused
Meteric::resume($subscription);  // state -> active
```

While a subscription is `paused`, `renew()` accrues nothing. No active service,
no invoice. The unpaid invoice that triggered the suspension still stands. A
`past_due` subscription keeps billing, which is what you want for contracts you
intend to dun rather than cut off.

`resume()` starts a fresh cycle from the resume date and bills it. The paused gap
is forgiven, so a customer is not back-billed for time the service was off, and
renewals continue from the new cycle. Pass an instant to resume at a specific
time: `Meteric::resume($subscription, $at)`.

Suspension works at the subscription and item level, not the addon. Addons and
configurable options are billed on the item's cycle and have no separate
lifecycle, so pausing the subscription suspends everything under it, and resuming
brings it all back. There is nothing addon-specific to toggle.

## Catch overdue invoices

Schedule the scan. It flags issued, unpaid invoices past `due_at` (set from
`config('meteric.invoice.net_days')`), moves their `active`/`trialing`
subscriptions to `past_due`, and fires `InvoiceOverdue` and `SubscriptionPastDue`.

```php
// routes/console.php
use Illuminate\Support\Facades\Schedule;

Schedule::command('meteric:mark-overdue')->daily();
```

## Suspend on overdue

The policy is yours. A common split: prepaid products get suspended, contracts
keep billing and get chased.

```php
use Meteric\Events\InvoiceOverdue;

class SuspendOverdue
{
    public function handle(InvoiceOverdue $event): void
    {
        foreach ($event->invoice->billedSubscriptions() as $subscription) {
            if ($this->isContract($subscription)) {
                continue; // keep invoicing, hand to debt collection
            }

            Meteric::pause($subscription);          // stop billing
            $this->provisioner->suspend($subscription); // stop the VM or gameserver
        }
    }
}
```

## Resume on payment

When the invoice is paid, resume the subscriptions it covered and start the
service. `Invoice::billedSubscriptions()` gives you the set to act on.

```php
use Meteric\Events\InvoicePaid;
use Meteric\Enums\SubscriptionState;

class ResumeOnPayment
{
    public function handle(InvoicePaid $event): void
    {
        foreach ($event->invoice->billedSubscriptions() as $subscription) {
            if ($subscription->state !== SubscriptionState::Paused) {
                continue;
            }

            Meteric::resume($subscription);
            $this->provisioner->start($subscription); // addons come back with the item
        }
    }
}
```

That is the whole loop. Overdue fires, you suspend. Payment lands, you resume.
Meteric tracks the money and the state, you map it to your infrastructure.

## Swapping models

Every Meteric model can be replaced with a host-app subclass, so you can add
relationships, casts, and methods of your own. Register the overrides once, in a
service provider's `register()`:

```php
use Meteric\Facades\Meteric;

Meteric::useInvoiceModel(App\Models\Invoice::class);
Meteric::useSubscriptionModel(App\Models\Subscription::class);
Meteric::useOrderModel(App\Models\Order::class);
```

An override must extend the model it replaces. The engine instantiates the
configured class everywhere, including relationships, so `$account->invoices()`
returns your subclass. Named helpers exist for the aggregate roots
(`useAccountModel`, `useSubscriptionModel`, `useChargeModel`, `useInvoiceModel`,
`usePaymentModel`, `useCreditNoteModel`, `useOrderModel`, `useUsageRecordModel`);
for any other model use `Meteric::useModel(Base::class, Override::class)`.
