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

## Wording an invoice line

Meteric titles a line after what it sells: the item's `label` if it has one,
otherwise the product name (`SubscriptionItem::lineTitle()`). A site that sells
one product as several different events wants the event on the line instead: a
domain registered, renewed or transferred is one product and three sentences,
and a metered dimension's key is an internal identifier no customer should read.

Implement `Meteric\Contracts\LineLabeller` and name it in
`config/meteric.php`:

```php
'line_labeller' => App\Billing\LineLabels::class,
```

```php
use Meteric\Contracts\LineLabeller;
use Meteric\Enums\ChargeReason;
use Meteric\Invoicing\LineContext;
use Meteric\Invoicing\LineLabel;

final class LineLabels implements LineLabeller
{
    public function label(LineContext $context): ?LineLabel
    {
        if ($context->item->product->type !== 'domain') {
            return null;    // meteric's own wording stands
        }

        $event = match ($context->reason) {
            ChargeReason::Initial => 'Create',
            ChargeReason::Renewal => 'Renew',
            default => null,
        };

        return new LineLabel($context->item->label.($event ? ' - '.$event : ''));
    }
}
```

The labeller is asked once, as each charge is written, and the answer is stored
on the charge. The invoice line, the document rendered from it and any
e-invoice built beside that document therefore all read one string and cannot
disagree about it. Nothing rewrites a line after the fact.

**Returning null keeps meteric's wording**, so a labeller answers for the lines
it knows and leaves the rest alone. A `LineLabel` replaces the title *and* the
description: carry `$context->description` into the second argument to keep the
one meteric wrote, or leave it out to take it away (a line whose period is
already printed from `covers` does not need it a second time).

`LineContext` carries the item, the reason, the wording meteric would have
used, and `attributes`, the whole charge row as it is about to be written, with
`kind()`, `covers()`, `dimensionId()` and `metadata()` over the fields a
labeller usually wants.

### The reason

`ChargeReason` says what meteric was doing, which no column on the charge
records: an item's first period and its fourth renewal are both `recurring`.

| Reason | Raised by |
|---|---|
| `Initial` | an order materializing, or a subscription's first cycle |
| `Renewal` | a period accrued because the last one ended, and a resume |
| `Change` | a plan change, or an item, addon or option booked mid-cycle |
| `Usage` | a metered dimension rolled up |
| `Other` | a caller that did not say |

`Charge::pendingForItem()` takes it as a third argument and defaults it to
`Other`, so a site raising its own charges from an item states the reason or
says nothing.
