# Models

Every model extends `MetericModel`: a UUID primary key (`HasUuids`,
non-incrementing string key) on a `meteric_`-prefixed table. Money columns are
stored as integer minor units (`*_minor`); per-unit rates that go sub-cent are
`numeric` strings. This page lists the key columns, relationships, and the
helper methods you actually call.

## Product

`meteric_products`: a catalog entry.

- **Columns:** `type`, `slug`, `name`, `pricing_model`, `is_proratable`, `config` (array).
- **Relationships:** `prices()`, `meterDimensions()`, `billable()` (morph).
- **Config:** the keys the package reads are validated on write. `config['downgrade']` must be a valid `DowngradePolicy` value and `config['cancel_notice_days']` a non-negative integer, or the assignment throws `InvalidArgumentException`. Other keys (your own host settings) pass through untouched.
- **Helpers:**
  - `priceFor(string $currency, PricePurpose $purpose = Recurring): ?Price`: latest open price for a currency and purpose.
  - `optionCatalog(float $qty = 1): array`: the configurable-option catalog as JSON-ready rows, values priced at `$qty`. See [Displaying options in a form](/usage/addons-and-options#displaying-options-in-a-form).
  - `isMetered(): bool`: true for `metered` / `hourly`.
  - `downgradePolicy(): DowngradePolicy`: from `config['downgrade']`, defaults to `Defer`.
  - `cancelNoticeDays(): int`: notice required before a contract ends, from `config['cancel_notice_days']`, defaults to `0`.

## Price

`meteric_prices`: versioned pricing for a product.

- **Columns:** `currency`, `amount_minor`, `unit_rate` (string), `purpose`, `pricing_model`, `interval`, `interval_count`, `billing_mode`, `setup_fee_minor`, `cap_minor`, `min_charge_minor`, `included_qty`, `block_size`, `percent`, `tiers` (array), `tax_inclusive`, `valid_from`, `valid_to`.
- **Casts:** `amount` is a `Money` over `amount_minor` + `currency`.
- **Relationships:** `product()`.
- **Helpers:**
  - `amount`: `Money`.
  - `amountFor(float|int|string $qty): Money`: `qty × unit_rate`, or the flat amount when no rate.
  - `amountForQuantity(float $qty): Money`: `amountFor` after the `included_qty` allowance and `block_size` rounding, clamped to `min_charge_minor` and `cap_minor`. Options and addons bill through this.
  - `billedUnits(float $qty): float`: the post-allowance, post-block unit count `amountForQuantity` prices.
  - `isRelative(): bool`: true for the `relative` model (a percentage of a base).
  - `amountOfBase(Money $base): Money`: `percent` of `$base`, for relative addons.
  - `percentLabel(): string`: `percent` without trailing zeros, e.g. `"20"` or `"12.5"`.
  - `recurrence(): RecurrenceRule`, `isRecurring(): bool`.
  - `hasSetupFee(): bool`, `setupFee(): Money`, `cap(): ?Money`.

## BillingAccount

`meteric_billing_accounts`: who is billed. Owns subscriptions and invoices, and
can have a parent for consolidated billing.

- **Columns:** `parent_id`, `owner_type` / `owner_id` (morph), `currency`, `tax_profile` (array).
- **Relationships:** `owner()` (morph), `parent()`, `children()`, `subscriptions()`, `invoices()`.
- **Helpers:**
  - `taxContext(bool $inclusive = false): TaxContext`: from the `tax_profile`.
  - `payerScopeIds(): array`: self plus children, for consolidated billing.

## Subscription

`meteric_subscriptions`: a customer's recurring billing agreement.

- **Columns:** `account_id`, `currency`, `state`, `anchor_mode`, `anchor_day`, `first_period`, `current_period` (Period), `trial_end`, `cancel_at`, `canceled_at`.
- **Relationships:** `account()`, `customer()` (morph), `items()`, `charges()`.
- **Helpers:**
  - `isBillable(): bool`, `isOnTrial(): bool`.
  - `nextRenewalAt(): ?CarbonImmutable`: earliest item period end.
  - `scopeDueForRenewal($q, CarbonImmutable $at)`: subscriptions whose period has ended.

## SubscriptionItem

`meteric_subscription_items`: a billed line within a subscription.

- **Columns:** `subscription_id`, `product_id`, `price_id`, `label` (line title, e.g. a hostname), `quantity`, `billing_mode` (nullable override), `state`, `current_period` (Period), `pending_change` (array), `resource_type` / `resource_id` (morph).
- **Relationships:** `subscription()`, `product()`, `price()`, `resource()` (morph), `addons()`, `options()`, `usageRecords()`.
- **Helpers:**
  - `lineTitle(): string`: the `label` if set, else the product name. Becomes the invoice line title.
  - `billingCycle(): ?Period`: the current cycle window (query your usage API for this range).
  - `billingMode(): BillingMode`: item override → price → `InAdvance`.
  - `periodAmount(): Money`: amount for one full period at the item's quantity.
  - `hasPendingChange(): bool`: a deferred plan change is queued.

## Charge

`meteric_charges`: money owed. The source of truth.

- **Columns:** `account_id`, `subscription_id`, `invoice_id`, `state`, `billing_mode`, `kind`, `amount_minor`, `currency`, `covers` (Period), `quantity`, `unit_rate`.
- **Casts:** `amount` is `Money`.
- **Relationships:** `account()`, `subscription()`, `invoice()`.
- **Helpers:**
  - `money(): Money`, `isCredit(): bool` (negative amount).
  - `markInvoiced(Invoice $invoice): void`: flip `pending` → `invoiced`.
  - `void(): void`.
  - `scopePending($q)`: pending charges only.

## Invoice

`meteric_invoices`: an immutable billing document.

- **Columns:** `account_id`, `number`, `driver`, `state`, `currency`, `subtotal_minor`, `tax_minor`, `total_minor`, `paid_minor`, `due_at`, `paid_at`.
- **Relationships:** `account()`, `lines()`, `charges()`, `creditNotes()`, `payments()`.
- **Helpers:**
  - `total(): Money`, `outstanding(): Money`.
  - `isPaid(): bool`, `isOverdue(): bool`.

## InvoiceLine

`meteric_invoice_lines`: one line of an invoice, with its own service period.

- **Columns:** `kind`, `description` (line title), `unit` (quantity unit: month, hours, GB), `quantity`, `unit_minor`, `unit_rate`, `amount_minor`, `tax_rate`, `tax_minor`, `currency`, `covers` (Period), `metadata`, `sort`.
- **Relationships:** `invoice()`, `charge()`.
- **Helpers:**
  - `gross(): Money`: amount + tax.
  - `coversLabel(string $format = 'Y-m-d'): ?string`: the service period as text, e.g. `2026-06-01 to 2026-06-30`.
  - `usedSummary(): ?string`: for usage lines, the total consumed and unit, e.g. `1500 GB` (from the rollup metadata).

## Payment / PaymentAllocation

`meteric_payments` and `meteric_payment_allocations`: inbound money and how it
maps to invoices.

- **Payment columns:** `account_id`, `amount_minor`, `currency`, `reference`, `received_at`.
- **Payment relationships:** `account()`, `allocations()`. Helper: `amount(): Money`.
- **PaymentAllocation:** `payment_id`, `invoice_id`, `amount_minor`; relationships `payment()`, `invoice()`.

## MeterDimension

`meteric_meter_dimensions`: a usage axis on a product.

- **Columns:** `key`, `unit`, `aggregation`, `rate` (string), `block_size`, `currency`, `included_qty`, `cap_minor`, `tiers` (array).
- **Relationships:** `product()`.
- **Helpers:**
  - `overage(float $used): float`: `used` minus allowance.
  - `billedUnits(float $used): float`: overage, or block count when `block_size` is set.
  - `amountFor(float $used): Money`: billed units × rate, clamped to the cap.

## UsageRecord

`meteric_usage_records`: a single reported usage event.

- **Columns:** `item_id`, `dimension_id`, `quantity`, `occurred_at`, `charge_id`, `window` (Period).
- **Relationships:** `item()`, `dimension()`.
- **Helpers:** `scopeUnbilled($q)`: records with no `charge_id`.

## Allowance

`meteric_allowances`: consumed-versus-included tracking for a dimension.

- **Columns:** `included_qty`, `consumed_qty`, `period` (Period).
- **Relationships:** `item()`, `dimension()`.
- **Helpers:** `remaining(): float`.

## Addon / ItemOption

Mid-cycle item extras.

- **Addon (`meteric_addons`):** `quantity`, `state`, `group_key`, `metadata`; relationships `item()`, `product()`, `price()`.
- **ItemOption (`meteric_item_options`):** `key`, `type` (`OptionType`), `value`, `quantity`, `min_qty`, `max_qty`; relationships `item()`, `price()`; helpers `boolValue(): bool`, `amount(): ?Money` (per-period recurring charge), `toDisplay(): array` (render-ready row for a service page).

## ProductOption / ProductOptionValue

A product's declared configurable options (the catalog). See
[Catalog options](/usage/addons-and-options#catalog-options).

- **ProductOption (`meteric_product_options`):** `product_id`, `key`, `label`, `type` (`OptionType`), `required`, `min_qty`, `max_qty`, `sort`; relationships `product()`, `values()`; helper `toDisplay(float $qty = 1): array` (option meta plus each value priced at `$qty`).
- **ProductOptionValue (`meteric_product_option_values`):** `option_id`, `value`, `label`, `price_id`, `sort`; relationships `option()`, `price()`; helpers `amountFor(float $qty = 1): ?Money` (charge at a quantity, null when free), `toDisplay(float $qty = 1): array` (render-ready value row with pricing knobs).

## BillingPeriod

`meteric_billing_periods`: the ledger of fully-billed windows. The GiST
`EXCLUDE` constraint on this table is the database guarantee that no window is
billed twice per item and dimension.

- **Columns:** `item_id`, `dimension_id`, `covers` (Period).
- **Relationships:** `item()`.

## TaxRate / TaxRegistration

The two tables behind the database tax driver. See [Tax](/usage/tax).

- **TaxRate (`meteric_tax_rates`):** `country`, `region`, `category`, `rate` (fraction string), `source`, `effective_from`, `effective_to`. Helper: `rateFraction(): float`, scope `activeOn($q, $date)`.
- **TaxRegistration (`meteric_tax_registrations`):** `country`, `scheme`, `number`, `valid_from`, `valid_to`. Scope `activeOn($q, $date)`.

## Coupon / Discount / CreditNote

- **Coupon (`meteric_coupons`):** `code`, `type`, `value`, `value_minor`, `max_redemptions`, `redeemed_count`, `valid_from`, `valid_to`. Helpers: `isValidAt(CarbonImmutable $at): bool`, `discountFor(Money $base): Money`.
- **Discount (`meteric_discounts`):** `remaining_cycles`; relationships `coupon()`, `target()` (morph).
- **CreditNote (`meteric_credit_notes`):** `state`, `number`, `reason`, `amount_minor` (net), `tax_minor` (mirrors the invoice VAT), `currency`; relationship `invoice()`; helper `amount(): Money`. See [Credit notes and refunds](/usage/invoicing#credit-notes-and-refunds).
