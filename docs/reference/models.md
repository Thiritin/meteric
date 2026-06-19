# Models

Every model extends `BillifyModel`: a UUID primary key (`HasUuids`,
non-incrementing string key) on a `billify_`-prefixed table. Money columns are
stored as integer minor units (`*_minor`); per-unit rates that go sub-cent are
`numeric` strings. This page lists the key columns, relationships, and the
helper methods you actually call.

## Product

`billify_products`: a catalog entry.

- **Columns:** `type`, `slug`, `name`, `pricing_model`, `is_proratable`, `config` (array).
- **Relationships:** `prices()`, `meterDimensions()`, `billable()` (morph).
- **Helpers:**
  - `priceFor(string $currency, PricePurpose $purpose = Recurring): ?Price`: latest open price for a currency and purpose.
  - `isMetered(): bool`: true for `metered` / `hourly`.
  - `downgradePolicy(): DowngradePolicy`: from `config['downgrade']`, defaults to `Defer`.

## Price

`billify_prices`: versioned pricing for a product.

- **Columns:** `currency`, `amount_minor`, `unit_rate` (string), `purpose`, `pricing_model`, `interval`, `interval_count`, `billing_mode`, `setup_fee_minor`, `cap_minor`, `tiers` (array), `tax_inclusive`, `valid_from`, `valid_to`.
- **Casts:** `amount` is a `Money` over `amount_minor` + `currency`.
- **Relationships:** `product()`.
- **Helpers:**
  - `amount`: `Money`.
  - `amountFor(float|int|string $qty): Money`: `qty × unit_rate`, or the flat amount when no rate.
  - `recurrence(): RecurrenceRule`, `isRecurring(): bool`.
  - `hasSetupFee(): bool`, `setupFee(): Money`, `cap(): ?Money`.

## BillingAccount

`billify_billing_accounts`: who is billed. Owns subscriptions and invoices, and
can have a parent for consolidated billing.

- **Columns:** `parent_id`, `owner_type` / `owner_id` (morph), `currency`, `tax_profile` (array), `balance_minor`.
- **Relationships:** `owner()` (morph), `parent()`, `children()`, `subscriptions()`, `invoices()`.
- **Helpers:**
  - `creditBalance(): Money`, `applyCredit(Money $amount): void`.
  - `taxContext(bool $inclusive = false): TaxContext`: from the `tax_profile`.
  - `payerScopeIds(): array`: self plus children, for consolidated billing.

## Subscription

`billify_subscriptions`: a customer commitment.

- **Columns:** `account_id`, `currency`, `state`, `anchor_mode`, `anchor_day`, `first_period`, `current_period` (Period), `trial_end`, `cancel_at`, `canceled_at`.
- **Relationships:** `account()`, `customer()` (morph), `items()`, `charges()`.
- **Helpers:**
  - `isBillable(): bool`, `isOnTrial(): bool`.
  - `nextRenewalAt(): ?CarbonImmutable`: earliest item period end.
  - `scopeDueForRenewal($q, CarbonImmutable $at)`: subscriptions whose period has ended.

## SubscriptionItem

`billify_subscription_items`: a billed line within a subscription.

- **Columns:** `subscription_id`, `product_id`, `price_id`, `quantity`, `billing_mode` (nullable override), `state`, `current_period` (Period), `pending_change` (array), `resource_type` / `resource_id` (morph).
- **Relationships:** `subscription()`, `product()`, `price()`, `resource()` (morph), `addons()`, `options()`, `commitment()`, `usageRecords()`.
- **Helpers:**
  - `billingMode(): BillingMode`: item override → price → `InAdvance`.
  - `periodAmount(): Money`: committed rate while a commitment is active, else the price amount.
  - `hasPendingChange(): bool`: a deferred plan change is queued.

## Charge

`billify_charges`: money owed. The source of truth.

- **Columns:** `account_id`, `subscription_id`, `invoice_id`, `state`, `billing_mode`, `kind`, `amount_minor`, `currency`, `covers` (Period), `quantity`, `unit_rate`.
- **Casts:** `amount` is `Money`.
- **Relationships:** `account()`, `subscription()`, `invoice()`.
- **Helpers:**
  - `money(): Money`, `isCredit(): bool` (negative amount).
  - `markInvoiced(Invoice $invoice): void`: flip `pending` → `invoiced`.
  - `void(): void`.
  - `scopePending($q)`: pending charges only.

## Invoice

`billify_invoices`: an immutable billing document.

- **Columns:** `account_id`, `number`, `driver`, `state`, `currency`, `subtotal_minor`, `tax_minor`, `total_minor`, `paid_minor`, `due_at`, `paid_at`.
- **Relationships:** `account()`, `lines()`, `charges()`, `creditNotes()`, `payments()`.
- **Helpers:**
  - `total(): Money`, `outstanding(): Money`.
  - `isPaid(): bool`, `isOverdue(): bool`.

## InvoiceLine

`billify_invoice_lines`: one line of an invoice, with its own service period.

- **Columns:** `kind`, `amount_minor`, `tax_rate`, `tax_minor`, `currency`, `covers` (Period), `quantity`, `unit_rate`, `sort`.
- **Relationships:** `invoice()`, `charge()`.
- **Helpers:** `gross(): Money`: amount + tax.

## Payment / PaymentAllocation

`billify_payments` and `billify_payment_allocations`: inbound money and how it
maps to invoices.

- **Payment columns:** `account_id`, `amount_minor`, `currency`, `reference`, `received_at`.
- **Payment relationships:** `account()`, `allocations()`. Helper: `amount(): Money`.
- **PaymentAllocation:** `payment_id`, `invoice_id`, `amount_minor`; relationships `payment()`, `invoice()`.

## MeterDimension

`billify_meter_dimensions`: a usage axis on a product.

- **Columns:** `key`, `aggregation`, `rate` (string), `currency`, `included_qty`, `cap_minor`, `tiers` (array).
- **Relationships:** `product()`.
- **Helpers:**
  - `billableQuantity(float $used): float`: `used` minus allowance.
  - `amountFor(float $used): Money`: billable × rate, clamped to the cap.

## UsageRecord

`billify_usage_records`: a single reported usage event.

- **Columns:** `item_id`, `dimension_id`, `quantity`, `occurred_at`, `charge_id`, `window` (Period).
- **Relationships:** `item()`, `dimension()`.
- **Helpers:** `scopeUnbilled($q)`: records with no `charge_id`.

## Allowance

`billify_allowances`: consumed-versus-included tracking for a dimension.

- **Columns:** `included_qty`, `consumed_qty`, `period` (Period).
- **Relationships:** `item()`, `dimension()`.
- **Helpers:** `remaining(): float`.

## Addon / ItemOption

Mid-cycle item extras.

- **Addon (`billify_addons`):** `quantity`, `state`, `group_key`, `metadata`; relationships `item()`, `product()`, `price()`.
- **ItemOption (`billify_item_options`):** `key`, `type`, `value`, `quantity`; relationships `item()`, `price()`; helper `boolValue(): bool`.

## Commitment

`billify_commitments`: a term commitment on an item.

- **Columns:** `term_interval`, `term_count`, `upfront_minor`, `rate_minor`, `currency`, `state`, `term` (Period), `early_term` (array).
- **Relationships:** `item()`.
- **Helpers:** `isActive(): bool`, `committedRate(): Money`.

## BillingPeriod

`billify_billing_periods`: the ledger of fully-billed windows. The GiST
`EXCLUDE` constraint on this table is the database guarantee that no window is
billed twice per item and dimension.

- **Columns:** `item_id`, `dimension_id`, `covers` (Period).
- **Relationships:** `item()`.

## TaxRate / TaxRegistration

The two tables behind the database tax driver. See [Tax](/usage/tax).

- **TaxRate (`billify_tax_rates`):** `country`, `region`, `category`, `rate` (fraction string), `source`, `effective_from`, `effective_to`. Helper: `rateFraction(): float`, scope `activeOn($q, $date)`.
- **TaxRegistration (`billify_tax_registrations`):** `country`, `scheme`, `number`, `valid_from`, `valid_to`. Scope `activeOn($q, $date)`.

## Coupon / Discount / CreditNote

- **Coupon (`billify_coupons`):** `code`, `type`, `value`, `value_minor`, `max_redemptions`, `redeemed_count`, `valid_from`, `valid_to`. Helpers: `isValidAt(CarbonImmutable $at): bool`, `discountFor(Money $base): Money`.
- **Discount (`billify_discounts`):** `remaining_cycles`; relationships `coupon()`, `target()` (morph).
- **CreditNote (`billify_credit_notes`):** `state`, `amount_minor`, `tax_minor`, `currency`; relationship `invoice()`; helper `amount(): Money`.
