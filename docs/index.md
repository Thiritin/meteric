# Introduction

Meteric is a billing engine for hosting systems, built as a Laravel package. It
handles subscriptions, proration, usage metering, addons, commitments, and
invoicing for products like VPS, domains, webhosting, cloud, and gameservers.
The design borrows from Stripe Billing and WHMCS and is sized for hosts running
real money through PostgreSQL.

It is not a payment gateway and not an accounting system. It computes what is
owed and when, then produces invoices. You connect a gateway for inbound
payments and, optionally, an accounting system as an invoice driver.

## The two ideas that matter

Most hosting billing tangles the money math into the application. Meteric keeps
two things separate and leans on the database to keep them honest.

### A charge is not an invoice

A `Charge` is money owed. It is the source of truth and it accrues on its own,
independent of any document. An `Invoice` is a document that bills a set of
charges.

A charge starts as `pending`. It flips to `invoiced` only after a driver
confirms the invoice was issued. If the driver throws, say your accounting
system is down or the API timed out, nothing flips. The charges stay `pending`
and the next run picks them up. You do not lose revenue to an outage.

```php
use Meteric\Facades\Meteric;

// Collect an account's pending charges and issue them via the bound driver.
// If issue() throws, every charge stays pending for the next run.
$invoice = Meteric::invoicePending($account);
```

### A service window is billed once

Billed periods are recorded in `meteric_billing_periods`. A PostgreSQL GiST
`EXCLUDE` constraint on that table rejects any overlapping window for the same
item and dimension. Renewals and usage rollups are idempotent because the
database refuses to record a window twice. A retried renewal is a no-op, not a
double charge.

## What you work with

| Concept | What it is |
|---------|-----------|
| `Product` / `Price` | Catalog entry and its versioned pricing: recurrence, billing mode, tiers, caps. |
| `Subscription` / `SubscriptionItem` | A customer commitment and its billed lines. Items can morph to the provisioned resource. |
| `Addon` / `ItemOption` | Bookable extras (+4 GB RAM) and configurable dimensions (gameserver slots). |
| `MeterDimension` / `UsageRecord` | Per-dimension usage (cpu-hours, traffic) for hourly and metered billing. |
| `Charge` | Money owed. Accrues `pending`, flips to `invoiced` only on driver success. |
| `Invoice` / `InvoiceLine` | An immutable document. Each line carries its own service period. |

Everything runs through the `Meteric` facade. The next pages get you installed
and writing your first subscription. Start with [Installation](/guide/installation),
or jump to the [Quickstart](/guide/quickstart).
