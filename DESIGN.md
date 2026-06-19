# Meteric — Design Document

Dynamic billing engine for hosting systems. Inspired by Stripe Billing + WHMCS.

**Status:** Draft · **Laravel:** 12 · **PHP:** 8.3+

---

## 1. Purpose & Scope

Meteric is a **calculation + invoicing engine** extracted from the host
application so all money math lives behind one well-tested boundary.

### In scope
- Pricing, proration, discounts, tax calculation.
- **Charge accrual** — money owed accumulates as `Charge` records independent of
  whether/when an invoice is written (see §2.5, the core architectural rule).
- Invoice generation via **pluggable invoice drivers**; charges marked invoiced
  only on confirmed driver success.
- Subscription lifecycle: cycles, renewals, upgrades/downgrades with proration.
- Morphable products (VPS, Domain, Webhosting, Cloud/metered, gameserver, …),
  **addons** and **configurable options** (per-slot, per-unit booking).
- Usage metering for dynamically-billed resources (hourly cloud, openstack-style).
- Pluggable tax — ships an **EU VAT** driver by default.
- Own database tables + Eloquent models + migrations.

### Out of scope (delegated to host app)
- **Payment gateway I/O.** Meteric is gateway-agnostic. It emits a payable
  invoice/charge intent and consumes payment results via events. Stripe/PayPal
  wiring lives in the app.
- **External invoice/accounting system I/O.** Meteric defines an `InvoiceDriver`
  contract and ships a local DB driver. PawHost binds a **Lexware Office
  (lexoffice)** driver. Network/auth/retry to the external system lives in the
  driver, not the core.
- **Tax law upkeep.** Meteric ships an EU VAT driver (rates, reverse-charge,
  B2B/B2C). Keeping rate tables current / MOSS reporting / non-EU regimes are the
  app's or a custom driver's job.
- **Dunning UI, emails, PDF rendering.** Meteric exposes data + events; app
  decides presentation and retry policy.

### Design tenets
1. **Money amounts are integer minor units; rates are high-precision decimals.**
   No floats. Stored amounts (charges, lines, totals, balances) = `bigint` minor
   units via `brick/money`. Unit *rates* (e.g. €0.001/GB) = `numeric(20,8)`
   major units. `amount = round(qty × rate)` to currency scale, rounded once at
   the charge/line boundary so invoices reconcile exactly.
2. **Charge ≠ invoice.** A charge is *money owed*; an invoice is a *document that
   bills it*. Charges accrue regardless of invoicing health. A charge is marked
   `invoiced` only after the invoice driver confirms success. Driver/system down
   ⇒ charges sit `pending`, never lost, picked up next run.
3. **Invoices are immutable once issued.** Corrections = credit notes, not edits.
4. **Drivers at the edges.** Tax and invoice emission are contracts with
   swappable drivers; core never hard-codes a tax regime or accounting system.
5. **Deterministic & pure where possible.** Calculators take inputs, return
   value objects. Side effects (persistence, events) at the edges.
6. **Time is injected.** A `Clock` abstraction — no `now()` inside calculators —
   so proration is testable to the second.
7. **Currency-correct.** Single currency per subscription/invoice; multi-currency
   supported across the system but never mixed within one invoice.

---

## 2. Domain Model

### Core entities

| Entity | Responsibility |
|--------|----------------|
| `Product` | Catalog definition. Morphs to a concrete `Billable` (VPS plan, domain TLD, hosting plan, metered SKU). Holds pricing model. |
| `Price` | A versioned price for a product: amount, currency, **recurrence (`interval` + `interval_count`)**, **billing timing (`in_advance` \| `in_arrears`)**, pricing model (fixed / tiered / per-unit / volume / hourly). |
| `Subscription` | A customer's ongoing commitment to one or more products. Owns billing cycle + state. |
| `SubscriptionItem` | Line within a subscription, each pointing at a `Price` + quantity. Morphs to the provisioned resource. May carry child `addons` + `option` selections. |
| `Addon` | Optional bookable extra attached to an item (e.g. +2GB RAM, +4GB RAM). Has own price; proratable. |
| `ConfigurableOption` | A dimension of an item billed by chosen quantity/value (e.g. gameserver *slots*, billed per slot). Priced per-unit or tiered. |
| `Charge` | **Money owed, the source of truth.** Accrues independently of invoicing. States: `pending → invoiced → settled` (+ `void`). Morphs to its origin (subscription item, usage, proration, one-off). Always records the **service period** `[covers_start, covers_end)` it bills and its `billing_mode` (advance/arrears) — so an invoice can state *"usage 2026-05-01 → 05-31"*. |
| `Invoice` | Immutable financial document produced by an `InvoiceDriver` from a batch of `Charge`s. Sum of `InvoiceLine`s. State machine. |
| `InvoiceLine` | Single billed charge/credit. Snapshots description, unit price, qty, tax, period. References originating `Charge`(s). |
| `UsageRecord` | Reported usage for metered items within a period (hourly cloud, per-resource). Rolls up into `Charge`s at close. Tagged with a `MeterDimension` so one resource can report many. |
| `MeterDimension` | A named usage dimension of a resource (AWS-style): `cpu_hour`, `traffic_out_gb`, `iops`, `slot_hour`. A resource can have several, each its own rate. |
| `IncludedAllowance` | Free/bundled units per cycle before overage (Hetzner included traffic, AWS free tier): `dimension`, `qty`, resets each period. |
| `Commitment` | Term/reservation: prepaid or minimum-term commitment that unlocks a discounted rate (AWS Reserved/Savings, WHMCS contract term). Has term, upfront, recurring, early-termination rule. |
| `BillingAccount` | Optional payer node. Subscriptions attach to an account; accounts can nest under a payer for **consolidated invoicing** (AWS org / reseller / agency). |
| `Discount` / `Coupon` | Percentage or fixed reduction, scoped + time-bounded. |
| `CreditNote` | Negative document correcting an issued invoice. |
| `Customer` (morph ref) | Meteric references the app's billable party polymorphically; does not own identity. |

### Morphable strategy

Two morph axes:

1. **Product → concrete plan.** `products.billable_type/billable_id` morphs to
   the host app's `VpsPlan`, `Tld`, `WebHostingPlan`, etc. Lets each product
   type carry its own attributes/provisioning metadata without Meteric knowing
   them.

2. **SubscriptionItem → provisioned resource.** `subscription_items.resource_type/
   resource_id` morphs to the running thing being billed (a specific VPS, a
   registered domain). Enables usage attribution and lifecycle linkage.

A product type opts into Meteric by implementing the `Billable` contract:

```php
interface Billable
{
    public function pricingModel(): PricingModel;   // Fixed|PerUnit|Tiered|Volume|Metered
    public function defaultPrice(string $currency): Price;
    public function isProratable(): bool;
    public function meter(): ?MeterDefinition;       // null = not metered
}
```

### 2.4 Addons & configurable options

A `SubscriptionItem` is the base product; **addons** and **configurable options**
extend it. Both bill on the item's cycle and prorate with it.

- **Addon** = optional bundled extra, booked on/off. Each addon is its own
  priced product. Mutually-exclusive groups supported (pick one tier).
  *Example: RAM upgrade group → `+2GB` (€2/mo) | `+4GB` (€3.50/mo).*
- **ConfigurableOption** = a quantity/value dimension of the item, priced by the
  chosen amount. *Example: gameserver `slots`, €0.30/slot/mo, qty 8 → €2.40.*
  Priced PerUnit, Tiered, or Volume. Step/min/max constraints enforced.

Both produce their own `InvoiceLine`s (and `Charge`s) so the breakdown stays
itemized: base plan, +RAM, slots ×8, each visible.

```php
$item->addAddon($ramPlus4Gb);          // swaps within the RAM group
$item->setOption('slots', 8);          // re-prices, prorated mid-cycle
```

### 2.5 Charge lifecycle — the core rule

> A **charge** is money owed. An **invoice** is a document that bills charges.
> They are decoupled so an external invoicing outage never loses revenue.

```
accrue                 batch + driver.issue() OK         payment event
  │                              │                            │
  ▼                              ▼                            ▼
pending  ───────────────────►  invoiced  ──────────────►  settled
  │  (driver down / deferred:    │  (linked to Invoice)
  │   stays pending, retried)    │
  └────────────► void ◄──────────┘  (correction / cancellation)
```

- Every billable event (renewal, proration, usage rollup, one-off, addon/option
  change) writes a `Charge` in `pending` **immediately**, even if no invoice is
  cut yet.
- An invoicing run collects a customer's `pending` charges, hands them to the
  bound `InvoiceDriver`, and only on **confirmed success** flips them to
  `invoiced` (atomically, linked to the returned invoice id).
- **Lexoffice down ⇒ driver throws ⇒ charges remain `pending`.** No partial
  state, no double-bill. Next run retries the same pending set (idempotency key
  per charge batch).
- `settled` set by inbound payment events. `void` for corrections (paired with a
  credit note if already invoiced).

This makes Meteric the durable ledger of truth; the external accounting system
is a downstream sink that may lag or fail without data loss.

---

## 3. Pricing Models

| Model | Use case | Calc |
|-------|----------|------|
| **Fixed** | Webhosting plan, fixed VPS | flat amount per cycle |
| **PerUnit** | Extra IPs, mailboxes, **gameserver slots** | `unit_price × qty` |
| **Tiered (graduated)** | Bandwidth where each tier priced separately | sum across crossed tiers |
| **Volume** | Storage where all units priced at the reached tier's rate | `tier_rate × qty` |
| **Metered** | Object storage GB, bandwidth GB | aggregate `UsageRecord`s × rate, billed in arrears |
| **HourlyUsage** | openstack-style cloud: VM uptime, CPU-hours, gameserver runtime | Σ(resource active-seconds ÷ 3600) × hourly rate, billed in arrears per cycle |
| **OneOff** | Domain registration, setup fee, SSL purchase | single non-recurring line |

**Hourly / openstack-style cloud:** the resource (a VM, a gameserver instance)
emits start/stop or sampled `UsageRecord`s. At cycle close Meteric aggregates
active time → hourly charge. Same engine bills *different* resources (a cloud VM
vs a gameserver) by attaching distinct `MeterDefinition`s — the math is shared,
the meter source differs. Caps/min-charge per resource configurable.

Each model is a strategy class implementing:

```php
interface PricingModel
{
    public function price(Quantity $qty, Price $price, BillingContext $ctx): Money;
}
```

### 3.1 Recurrence — dynamic intervals (Stripe-style)

No fixed monthly-only cycle. A `Price` carries `interval ∈ {day, week, month,
year}` + `interval_count`. The cycle is **`every interval_count × interval`**.

- *every 7 days, every 2 weeks, every 3 months, every 1 year* — all valid.
- `RecurrenceRule` value object computes the next period end from a period start
  via the injected `Clock` (calendar-aware: month/year add real calendar units,
  so the 31st→Feb clamps correctly; day/week add fixed durations).
- `interval_count = null` ⇒ one-off (no recurrence).
- Proration ratio uses the **actual** period length the rule produced, so a
  "every 3 months" cycle prorates against its real day/second span, not a
  nominal 30 days.
- Each `SubscriptionItem` can have its own recurrence; the subscription's next
  `RenewalDue` is the earliest item period end (unless `anchor` aligns them).

```php
$recurrence = new RecurrenceRule(Interval::Month, count: 3);  // quarterly
$next = $recurrence->nextEnd($periodStart, $clock);
```

### 3.2 Billing timing — prepaid / postpaid / mixed / bill-now

A second axis, **independent of recurrence and orthogonal to pricing model**.
Set per `Price`, so components of one subscription can differ.

| Mode | When charge accrues | Service period it covers | Typical |
|------|--------------------|--------------------------|---------|
| **`in_advance`** (prepaid) | at period **start** | the upcoming period | fixed plans, base fees |
| **`in_arrears`** (postpaid) | at period **end** | the period that just elapsed | metered/hourly usage |

- **Mixed subscription** is normal: e.g. cloud server where the **base fee is
  prepaid** (charged for the period ahead) and **usage is postpaid** (charged
  after the period, for elapsed hours). One subscription, two `SubscriptionItem`s
  (or one item + a metered component) with different `billing_mode`.
- Every resulting `Charge`/`InvoiceLine` carries its **own** `[covers_start,
  covers_end)`. So a single invoice can read:
  - *Base fee — service 2026-06-01 → 06-30 (in advance)*
  - *CPU hours — usage 2026-05-01 → 05-31 (in arrears)*
  These deliberately span **different windows** on the same document.
- **Bill-now / checkout:** an order can demand an *immediate* invoice rather than
  waiting for the next invoicing run. `Meteric::checkout()` accrues the order's
  charges and invoices them at once via the bound driver. Prepaid items charge
  their first period immediately; arrears items accrue €0 now and bill at first
  period end.
- Postpaid items affect **dunning/credit risk** (service consumed before paid) —
  surfaced via events; policy is the app's.

```php
$plan  = Price::recurring(Money::of(1000,'EUR'), Interval::Month)->inAdvance();
$usage = Price::metered(Money::of(5,'EUR'), Meter::CpuHour)->inArrears();
```

### 3.3 Allowances, caps, commitments, multi-dimension metering

Concepts needed to match AWS / Hetzner. All compose with the timing + recurrence
axes above.

- **Multi-dimension metering (AWS).** One resource (a VM) reports several
  `MeterDimension`s — `cpu_hour`, `traffic_out_gb`, `iops`, `storage_gb_hour` —
  each priced independently. The item fans out to one `Charge` per dimension at
  close. Replaces the old one-meter-per-item assumption.
- **Included allowance + overage (Hetzner traffic, AWS free tier).** A dimension
  can carry an `IncludedAllowance` (e.g. 20 TB traffic included). Usage ≤
  allowance ⇒ €0; only the overage is charged. Allowance resets each cycle.
- **Monthly cap on hourly (Hetzner cloud).** `HourlyUsage` price carries an
  optional `cap` ⇒ charge = `min(hours × rate, cap)`. Bill by the hour, never
  exceed the monthly flat price. Also a `min_charge` floor.
- **Commitments / reservations (AWS RI & Savings, WHMCS term).** A `Commitment`
  locks a term (1yr/3yr), optional upfront (prepaid in advance), and a reduced
  usage/recurring rate for its lifetime; matching usage bills at the committed
  rate, excess at on-demand. Early termination → fee rule.
- **Consolidated billing (AWS org / reseller).** `BillingAccount` nesting lets
  many subscriptions roll into one payer invoice while still itemized per account.

#### Configurable option *types* (WHMCS parity)

`ConfigurableOption` supports several input types, each priced:
- **Quantity** — per-unit/tiered (gameserver slots, extra IPs).
- **Choice (dropdown/radio)** — pick one priced value (datacenter location, OS
  license, disk type) → fixed delta on the line.
- **Toggle (yes/no)** — boolean add-on flag with a flat price.

`Addon` = a separately-marketed product attached to an item; `ConfigurableOption`
= a dimension *of* the item. Both itemize + prorate.

#### Setup / one-time fees (WHMCS parity)

A `Price` may carry a one-time `setup_fee` billed once on first
provision/activation, distinct from the recurring line and from generic `OneOff`
products.

#### IP projects / per-resource recurring (Hetzner-style)

An **IP project** = a pool of IP addresses billed per IP per cycle. Modeled as a
`SubscriptionItem` whose `resource` morphs to the IP/subnet entity, priced
PerUnit (€/IP/mo) or as a subnet block. Allocating an IP mid-cycle → prorated
add; releasing → prorated credit (same engine as addons/slots). A subnet `/29`
can bill as 8 units or one block — config. Floating/primary IPs = the same
pattern, distinct products.

### 3.4 Anchoring & first-period billing

Controls *when periods renew* and *what the first invoice covers*. Global default
in config, overridable per product/subscription.

**Anchor mode** — where the recurring boundary sits:
- `signup` (anniversary): period runs from signup; renews every `interval×count`
  from that instant. *Order 25th, monthly ⇒ renews 25th.*
- `fixed_day`: align all items to a calendar day (e.g. the **1st**). The first
  period is the stub from signup → next anchor. *Order 25th ⇒ stub 25th→1st.*
- `fixed_dow` / custom: same idea for weekly cycles.

**First-period policy** — what the first invoice bills when aligning to an anchor:

| Policy | Order VPS on the 25th (monthly, anchor = 1st) | First invoice |
|--------|----------------------------------------------|---------------|
| `prorate_only` | bill the 5-day stub now | **5 days**, then full month on the 1st |
| `prorate_plus_full` | stub **+** the first full upcoming period | **5 days + 30 days**, then renews 1st |
| `full_period` | ignore stub, bill one full period now, anchor from there | **30 days** |
| `free_until_anchor` | stub is free, first real charge at anchor | **€0**, then full month on 1st |

`prorate_plus_full` is the WHMCS "pay remainder + next month" behaviour the user
asked for. The stub charge and the full-period charge are **separate lines**,
each with its own `[covers_start, covers_end)` (ties into §3.2).

- Anchor + policy compose with billing-timing: prepaid stub bills now, an
  arrears component starts metering at signup and bills its window at period end.
- Multiple items aligning to one anchor share a renewal date ⇒ one consolidated
  renewal invoice.

### 3.5 Package changes (plan switch mid-life)

Distinct from quantity/addon edits: switching the **base product/plan** of a
running item (Webhosting Starter → Pro, VPS S → XL, even across product *types*
where the host allows).

Direction is detected by price; the two directions behave differently:

- **Upgrade** (new > old): charge the **prorated difference now** — credit the
  old plan's unused portion + charge the new plan's prorated portion, both to the
  change instant within the current period. You want more capacity → pay the gap.
- **Downgrade** (new < old): a `DowngradePolicy`, per product (`product.config`)
  or per call. **Neither refunds, credits, nor extends** — they differ only on
  timing:
  - `defer` — keep the higher tier until the paid period ends, then renew at the
    lower price (contracts). Stored as a `pending_change`, applied at renewal.
  - `discard` — switch to the lower plan **now**; the unused value of the higher
    plan is **forfeited** (prepaid). No money moves.
- **Provisioning-agnostic.** Meteric never resizes the resource. The host app
  performs the stop/resize/start and calls `changePlan(..., at: $whenDone)`; the
  reported instant is what bills. For `defer`, the app resizes at the boundary.
- **Hourly / metered** plans: a change is just a **rate change going forward** —
  no proration. Hours before the change bill at the old tier's rate, hours after
  at the new (each tier carries its own meter dimension; usage rolls up per
  dimension). Use `discard` to switch the rate immediately.
- Crossing recurrence (monthly → annual) re-anchors the period per §3.4.

### 3.6 Quote / Preview API (checkout rendering)

**First-class, read-only calculation** so a client app can build a checkout and
show live prices *before* anything is persisted. Same calculators as real billing
⇒ the quote always matches the eventual invoice.

```php
$quote = Meteric::quote($customer)          // or ::quote() anonymous, for pricing pages
    ->anchor(Anchor::fixedDay(1))
    ->firstPeriod(FirstPeriodPolicy::ProratePlusFull)
    ->add($vpsPlan)
    ->add($gameserver, options: ['slots' => 16], addons: [$ramPlus4Gb])
    ->coupon('WELCOME50')
    ->at($checkoutInstant)                   // injected clock → deterministic
    ->build();                               // NO DB writes
```

`Quote` is a serializable value object → JSON for the frontend:

```jsonc
{
  "currency": "EUR",
  "due_now":   { "subtotal_minor": 4200, "tax_minor": 798, "total_minor": 4998 },
  "recurring": { "interval": "month", "interval_count": 1, "total_minor": 1190,
                 "next_charge_at": "2026-07-01T00:00:00Z" },
  "lines": [
    { "label": "VPS XL", "kind": "prorated", "covers": ["2026-06-25","2026-07-01"],
      "qty": 1, "unit_minor": 1000, "amount_minor": 200, "tax_minor": 38 },
    { "label": "VPS XL", "kind": "full_period", "covers": ["2026-07-01","2026-08-01"],
      "qty": 1, "unit_minor": 1000, "amount_minor": 1000, "tax_minor": 190 },
    { "label": "Gameserver slots", "kind": "recurring", "qty": 16,
      "unit_minor": 30, "amount_minor": 480 },
    { "label": "RAM +4GB", "kind": "addon", "amount_minor": 350 },
    { "label": "WELCOME50 (-50%)", "kind": "discount", "amount_minor": -515 }
  ],
  "estimated": false   // true if it contains arrears/usage components not yet known
}
```

- Quote covers every mutation: new order, add item, **change plan**, change qty,
  toggle option/addon, change interval, apply coupon — each returns the
  due-now + recurring breakdown.
- Usage/arrears components are shown as `estimated: true` (no actual usage yet)
  with the rate, so checkout can say "+ €0.05/GB traffic, billed monthly."
- `Meteric::quoteChange($item, ...)` previews an upgrade/downgrade proration for
  an existing customer ("you'll be charged €X now to upgrade").
- Pure function of inputs + clock ⇒ unit-testable, cacheable, safe to call from
  a public pricing page.

---

## 4. Use Cases (the spec)

Grouped by lifecycle. Each is a target acceptance test.

### 4.1 Catalog & pricing
- UC-01 Define a fixed-price recurring product (webhosting plan, €9.99/mo).
- UC-02 Define a one-off product (domain `.com` registration, €12/yr — recurring
  yearly but provisioned once).
- UC-03 Define a metered product (cloud bandwidth, €0.05/GB billed monthly in
  arrears).
- UC-04 Define tiered/volume pricing (storage: 0–100GB free, 100–500 @ x, 500+ @ y).
- UC-05 Version a price without breaking existing subscriptions (grandfathering).
- UC-06 Multiple currencies for the same product.
- UC-07 Dynamic recurrence: every 7 days, every 2 weeks, every 3 months, every 1
  year — all bill on the right date via `RecurrenceRule`.
- UC-08 Define an addon group (RAM: +2GB | +4GB, mutually exclusive).
- UC-09 Define a configurable option (gameserver slots, per-unit, min 4/max 64).

### 4.1b Addons & configurable options
- UC-A1 Book an addon mid-cycle → prorated addon charge, own line.
- UC-A2 Swap addon within a group (+2GB → +4GB): prorate credit + charge.
- UC-A3 Increase slots 8 → 16 mid-cycle → prorated per-slot charge.
- UC-A4 Decrease slots mid-cycle → prorated credit, step/min enforced.
- UC-A5 Addon/option lines remain itemized on the invoice, not merged into base.

### 4.1g Anchoring & first period
- UC-AN1 `fixed_day` anchor: order 25th → stub 25th→1st, renews on the 1st.
- UC-AN2 `prorate_plus_full`: first invoice = 5-day stub **+** full next month, as
  two separate dated lines; then renews on the 1st.
- UC-AN3 `free_until_anchor`: stub free, first real charge at the anchor.
- UC-AN4 Many items aligned to one anchor → single consolidated renewal invoice.
- UC-AN5 Per-product anchor override beats the global default.

### 4.1h Package / plan changes
- UC-PC1 Switch base plan mid-cycle (Starter→Pro): prorated credit + charge.
- UC-PC2 Scheduled change applied at period end, not now.
- UC-PC3 Deferred downgrade (`defer`): keep higher tier till period end, lower next cycle.
- UC-PC3b Discard downgrade (`discard`, prepaid): switch now, unused value forfeited.
- UC-PC4 Plan change crossing interval (monthly→annual) re-anchors correctly.
- UC-PC5 `quoteChange()` returns the exact proration before the change commits.

### 4.1i Quote / preview (checkout)
- UC-Q1 Anonymous quote for a pricing page (no customer, no DB).
- UC-Q2 Quote a full cart (plan + options + addons + coupon) → due-now +
  recurring breakdown, dated lines.
- UC-Q3 Quote with `prorate_plus_full` matches the invoice later issued (golden).
- UC-Q4 Arrears/usage lines flagged `estimated` with their rate.
- UC-Q5 Quote serializes to stable JSON for a frontend.

### 4.1d Billing timing
- UC-T1 Prepaid item: charge accrues at period start, covers the upcoming period.
- UC-T2 Postpaid item: charge accrues at period end, covers the elapsed period.
- UC-T3 Mixed subscription: base fee prepaid + usage postpaid on one invoice,
  each line stamped with its own `[covers_start, covers_end)`.
- UC-T4 Invoice/charge correctly states the usage timeframe (different window
  from the prepaid line on the same document).
- UC-T5 **Bill-now at checkout:** order → immediate invoice via driver (no wait
  for next run). Prepaid first period charged now; arrears starts at €0.
- UC-T6 First-period proration combined with prepaid (sign up mid-period, pay
  prorated remainder in advance).
- UC-T7 Switching an item advance↔arrears mid-life handled without double/zero
  billing the overlap period.

### 4.1c Hourly / cloud usage
- UC-H1 openstack VM billed by active uptime; start/stop records → hourly charge.
- UC-H2 Gameserver billed by runtime hours via its own meter, same engine.
- UC-H3 Resource destroyed mid-cycle: bill only active hours, no future charge.
- UC-H4 Sampled usage gaps handled; min-charge / cap per resource applied.
- UC-H5 **Multi-dimension:** one VM bills cpu-hours + traffic-out + IOPS, each its
  own rate, separate lines (AWS).
- UC-H6 **Monthly cap:** hourly server caps at the monthly flat price (Hetzner).
- UC-H7 **Included allowance:** 20 TB traffic free, only overage billed; resets
  next cycle (Hetzner / AWS free tier).
- UC-H8 **Commitment:** 1-yr reservation with upfront → committed rate applies;
  excess usage at on-demand rate; early termination fee (AWS RI / WHMCS term).

### 4.1e IP projects / per-resource recurring
- UC-IP1 Bill a pool of IPv4 at €/IP/mo; allocate IP mid-cycle → prorated add.
- UC-IP2 Release an IP mid-cycle → prorated credit; project total recalculated.
- UC-IP3 Subnet `/29` billed as a block or as 8 units (config).
- UC-IP4 Floating/primary IP as a distinct recurring product on the resource.

### 4.1f Consolidated / reseller billing
- UC-RB1 Many subscriptions across nested accounts roll into one payer invoice,
  itemized per account (AWS org / reseller).

### 4.2 Subscriptions
- UC-10 Subscribe a customer to a monthly plan; first invoice issued for the
  period.
- UC-11 Subscribe with an aligned billing anchor (all items renew on the 1st).
- UC-12 Add an item mid-cycle → **prorated** charge for remaining days.
- UC-13 Remove an item mid-cycle → **prorated credit** to next invoice.
- UC-14 Upgrade plan mid-cycle (VPS small→large): credit unused + charge new,
  prorated to the second.
- UC-15 Downgrade mid-cycle: prorated credit, optionally deferred to cycle end.
- UC-16 Change quantity mid-cycle (scale mailboxes 5→10).
- UC-17 Pause / resume a subscription (suspension, no charge while paused).
- UC-18 Cancel at period end vs immediate cancel (with/without proration refund).
- UC-19 Trial period (N days free, then first real invoice).
- UC-20 Change billing interval (monthly → annual) with proration.

### 4.3 Billing cycle / renewal
- UC-30 Cycle renewal generates the next invoice automatically (scheduled job).
- UC-31 Idempotent renewal — running the renewal job twice never double-bills.
- UC-32 Metered items: close usage window, aggregate, add arrears line at renewal.
- UC-33 Anniversary vs calendar-month billing.
- UC-34 Leap year / 28–31 day month / DST-correct proration.

### 4.4 Invoicing
- UC-40 Generate a draft invoice; recalc while draft; finalize → immutable.
- UC-41 Combine recurring + one-off + metered + proration on one invoice.
- UC-42 Apply discount/coupon (percentage and fixed) at line and invoice scope.
- UC-43 Apply tax via resolver; show net/tax/gross breakdown per line.
- UC-44 Round correctly; total reconciles to sum of lines (no rounding drift).
- UC-45 Mark invoice paid / partially paid / overdue via payment events.
- UC-46 Void an issued invoice (only if unpaid).
- UC-47 Issue a credit note against a paid invoice (refund/correction).
- UC-48 Account credit balance applied to next invoice.

### 4.4b Charges & invoice drivers
- UC-C1 Renewal accrues `pending` charges immediately, before any invoice cut.
- UC-C2 Invoicing run hands pending charges to driver; success → `invoiced`.
- UC-C3 **Driver (lexoffice) throws/times out → charges stay `pending`, no
  partial state, no revenue lost.** Next run retries same batch.
- UC-C4 Idempotency: retrying a batch never produces a duplicate external invoice.
- UC-C5 Charges deferrable (hold a charge `pending` on purpose, bill later).
- UC-C6 Local DB driver and lexoffice driver produce equivalent line data.
- UC-C7 Charge voided before invoicing disappears cleanly; after invoicing →
  credit note.

### 4.5 Discounts & tax
- UC-50 Time-bounded coupon (first 3 cycles 50% off).
- UC-51 Stack vs exclusive discounts (config-driven precedence).
- UC-52 Tax-inclusive vs tax-exclusive pricing per region.
- UC-53 Tax exemption (valid VAT id → reverse charge → 0% line) via resolver.

### 4.6 Edge / safety
- UC-60 Currency mismatch within an invoice is rejected.
- UC-61 Negative invoice total clamps to credit note, never a negative charge.
- UC-62 Proration of a zero-day window = €0.
- UC-63 Concurrent renewal + manual change uses locking; no lost update.
- UC-64 All money operations are integer-safe; assert no float anywhere.

---

## 5. Proration

The hard part. Rules:

- Proration unit = **seconds** by default (config: seconds|days), anchored to the
  item's current billing period `[start, end)`.
- `prorated = unit_price × (remaining_seconds / total_period_seconds)`, rounded
  with the invoice's rounding mode at the **line** level.
- Upgrade = credit old item's unused portion + charge new item's used portion,
  both within the same anchor period.
- Credits never exceed what was charged for that period.
- Proration is itself a value object (`Proration { from, to, ratio, amount }`)
  attached to the resulting `InvoiceLine` for auditability.

```php
final class Proration {
    public function __construct(
        public readonly CarbonImmutable $periodStart,
        public readonly CarbonImmutable $periodEnd,
        public readonly CarbonImmutable $changeAt,
        public readonly Money $fullAmount,
    ) {}
    public function ratio(): float;   // remaining / total
    public function amount(): Money;  // signed
}
```

---

## 6. Money, Tax, Rounding

- **Money amounts:** `brick/money` value object, integer minor units,
  currency-typed. Currency scale (2 for EUR, 0 for JPY, 3 for BHD) comes from
  `brick/money` — never hardcode `/100`.
- **Rates:** stored as `numeric(20,8)` major units so sub-cent pricing
  (€0.001/GB, €0.0000125/CPU-sec) is exact. `MoneyMath::fromRate(qty, rate, ccy)`
  multiplies in `BigDecimal` and rounds to currency minor → the billable amount.
- **Rounding:** configurable mode (default `HALF_UP`), applied once per line at
  the charge boundary; invoice total = Σ line totals (so it always reconciles).
- **Tax resolver contract:**

```php
interface TaxResolver {
    public function resolve(InvoiceLine $line, TaxContext $ctx): TaxResult; // rate, amount, label, exempt?
}
```
Default is **`DatabaseTaxResolver`** — a configurable, multi-jurisdiction engine
backed by two editable tables:

- **`meteric_tax_registrations`** — where the merchant is VAT-registered. Presence
  of a registration (direct, or an `eu_oss` row covering all EU destinations) is
  what *authorises* charging tax. No registration ⇒ out of scope (0%).
- **`meteric_tax_rates`** — the rates themselves, date-versioned, per product
  category (`standard`/`reduced`/`lodging`/…). EU rows kept fresh by
  `php artisan meteric:vat-sync` (pulls from ibericode, `source='ibericode'`);
  non-EU jurisdictions (**Switzerland**, UK, Norway, …) added as `manual` rows.

So registering for Swiss VAT = add a `CH` registration + CH rate rows (8.1% /
2.6% / 3.8%); CH customers are then charged Swiss VAT while EU stays on OSS. EU
cross-border B2B reverse charge is confirmed via **VIES**.

Other shipped resolvers: **`IbericodeVatResolver`** (live EU-only + VIES),
`EuVatResolver` (static offline EU fallback), `FlatRateTaxResolver`,
`NullTaxResolver`. App can bind a fully custom resolver. ibericode is a *rate
source* feeding the table, not the whole tax story.

---

## 6b. Invoice Drivers

Invoice *emission* is a swappable driver. Core builds the invoice value object
(lines, tax, totals); the driver persists/transmits it and returns an identifier.

```php
interface InvoiceDriver {
    /** @param Charge[] $charges  Throw on failure — charges stay `pending`. */
    public function issue(InvoiceDraft $draft, array $charges): IssuedInvoice; // external id, number, url
    public function void(IssuedInvoice $invoice): void;
    public function creditNote(IssuedInvoice $invoice, CreditNoteDraft $draft): IssuedCreditNote;
}
```

- Ships **`DatabaseInvoiceDriver`** (writes Meteric's own invoice tables — the
  default, always-available sink).
- PawHost binds **`LexofficeInvoiceDriver`** (maps draft → lexoffice API,
  handles auth/retry/rate-limit, returns the lexoffice voucher id).
- Contract is the failure boundary: a thrown exception leaves all charges
  `pending` and writes no local `invoiced` state ⇒ the §2.5 guarantee holds.
- Drivers may be **chained/mirrored** (e.g. DB driver for the canonical record +
  lexoffice for accounting), config-selected per customer/region.

---

## 7. State Machines

**Subscription:** `incomplete → trialing → active → past_due → paused → canceled`
(+ `expired`). Transitions guarded; illegal transitions throw.

**Charge:** `pending → invoiced → settled` (+ `void`). `pending → invoiced` only
on confirmed `InvoiceDriver` success; reversible to `pending` never (the batch is
atomic). See §2.5.

**Invoice:** `draft → open(issued) → paid` | `void` | `uncollectible`;
`open → partially_paid → paid`. Once `open`, lines immutable.

---

## 8. Events

Emitted for app to react (provisioning, email, gateway):

`SubscriptionCreated/Updated/Canceled/Paused/Resumed`,
`SubscriptionItemAdded/Removed/QuantityChanged/Changed`,
`PlanChangeScheduled/Applied`,
`AddonBooked/Swapped/Removed`, `OptionChanged`,
`ChargeAccrued/Invoiced/Voided`, `InvoiceIssueFailed` (driver down — for alerting),
`InvoiceDrafted/Issued/Paid/PartiallyPaid/Voided/PaymentFailed`,
`CreditNoteIssued`, `UsageRecorded`, `ProrationApplied`, `RenewalDue`.

Payment is **inbound** too: app calls `Meteric::recordPayment($invoice, $amount,
$ref)` after the gateway settles → drives invoice state.

---

## 9. Public API (sketch)

```php
$sub = Meteric::subscribe($customer)
    ->add($vpsPlan, qty: 1)
    ->add($extraIp, qty: 2)
    ->anchor(BillingAnchor::FirstOfMonth)
    ->trialDays(14)
    ->currency('EUR')
    ->create();

$sub->add($gameserver, qty: 1)
    ->withOption('slots', 16)                    // per-slot billing
    ->withAddon($ramPlus4Gb);                    // addon group

$sub->changeItem($item, newPrice: $vpsLarge);    // prorated upgrade
$sub->item($gameserver)->setOption('slots', 32); // prorated mid-cycle
$sub->recordUsage($cloudVm, hours: 312.5);       // hourly cloud

$sub->cancel(at: CancelAt::PeriodEnd);

// Quarterly price, dynamic recurrence
$price = Price::recurring(Money::of(2999,'EUR'), Interval::Month, every: 3);

// Bill-now at checkout: accrue + invoice immediately (prepaid charged now)
$invoice = Meteric::checkout($customer)
    ->add($vpsPlan)                               // in_advance → first period now
    ->add($cloudUsage)                            // in_arrears → €0 now, bills later
    ->issue();                                    // uses bound InvoiceDriver

// Renewal accrues PENDING charges (no invoice yet)
$charges = Meteric::accrueDueCharges($sub);      // idempotent

// Invoicing run: pending charges → driver. Driver down ⇒ charges stay pending.
$invoice = Meteric::invoicePending($customer);   // uses bound InvoiceDriver
Meteric::recordPayment($invoice, Money::of(999,'EUR'), 'pi_123');
```

---

## 10. Persistence (tables)

`meteric_products`, `meteric_prices`, `meteric_subscriptions`,
`meteric_subscription_items`, `meteric_addons`, `meteric_item_options`,
`meteric_meter_dimensions`, `meteric_allowances`, `meteric_commitments`,
`meteric_billing_accounts`, `meteric_charges`, `meteric_invoices`,
`meteric_invoice_lines`, `meteric_usage_records`, `meteric_discounts`,
`meteric_coupons`, `meteric_credit_notes`, `meteric_ledger` (optional
double-entry audit).

- `meteric_prices`: `interval ENUM(day,week,month,year)` + `interval_count INT`
  + `billing_mode ENUM(in_advance,in_arrears)`.
- `meteric_charges`: `state`, `billing_mode`, `morph(origin)`, `amount_minor`,
  `currency`, `covers_start`, `covers_end` (the billed service period),
  `invoice_id NULL`, `idempotency_key`. Indexed on `(customer, state)` for the
  invoicing run. `covers_*` copied onto `meteric_invoice_lines` for display.
- All money columns: `*_minor BIGINT` + `currency CHAR(3)`. Morphs: `*_type` +
  `*_id`. Optimistic locking via `version` on subscriptions/invoices.

---

## 11. Testing Strategy

- Every UC in §4 = a feature/unit test.
- Property-based tests for proration (ratio ∈ [0,1], credit ≤ charge,
  Σlines == total) using injected `Clock`.
- Golden-file invoice snapshots.
- Mutation testing target on calculators (Infection).
- No float assertion lint across `src/`.

---

## 12. Package Skeleton

```
src/
  Contracts/        Billable, PricingModel, TaxResolver, InvoiceDriver, Clock
  Models/           Product, Price, Subscription, SubscriptionItem,
                    Addon, ItemOption, Charge, Invoice, InvoiceLine, ...
  Pricing/          Fixed, PerUnit, Tiered, Volume, Metered, HourlyUsage
  Recurrence/       RecurrenceRule, Interval
  Anchoring/        Anchor, FirstPeriodPolicy, PeriodPlanner
  Proration/        Proration, Prorator
  Quoting/          Quote, QuoteBuilder, QuoteLine (read-only, no persistence)
  Charges/          ChargeAccruer, ChargeRepository
  Invoicing/        InvoiceBuilder, InvoiceRunner,
                    Drivers/ DatabaseInvoiceDriver, (LexofficeInvoiceDriver = app)
  Subscriptions/    SubscriptionManager, CycleScheduler
  Tax/              EuVatResolver, FlatRateTaxResolver, NullTaxResolver
  Events/           ...
  Meteric.php       facade-backed entrypoint
  MetericServiceProvider.php
database/migrations/
config/meteric.php   # tax driver, invoice driver(s), proration unit, rounding
tests/
```

Drivers are resolved from config (`meteric.invoice.driver`, `meteric.tax.driver`)
so PawHost binds `LexofficeInvoiceDriver` + `EuVatResolver` without touching core.

---

## 13. Reference-System Coverage

Does this design cover what WHMCS / AWS / Hetzner do? Matrix:

| Capability | WHMCS | AWS | Hetzner | Meteric |
|------------|:-----:|:---:|:-------:|---------|
| Recurring cycles (mo/qtr/yr/biennial…) | ✓ | – | ✓ | ✓ dynamic `interval×count` |
| Per-second/hourly usage | – | ✓ | ✓ | ✓ `HourlyUsage` |
| Multi-dimension metering per resource | – | ✓ | ✓ | ✓ `MeterDimension` |
| Included allowance + overage | – | ✓ | ✓ | ✓ `IncludedAllowance` |
| Monthly cap on hourly | – | – | ✓ | ✓ `cap` |
| Tiered / volume pricing | ✓ | ✓ | ✓ | ✓ |
| Product addons | ✓ | – | ✓ | ✓ `Addon` |
| Configurable options (qty/choice/toggle) | ✓ | – | ✓ | ✓ option types |
| Setup / one-time fees | ✓ | – | ✓ | ✓ `setup_fee` + `OneOff` |
| Prepaid / postpaid / mixed | ✓ | ✓ | ✓ | ✓ `billing_mode` |
| Bill-now at checkout | ✓ | – | ✓ | ✓ `checkout()` |
| Proration on up/downgrade & qty | ✓ | ✓ | ✓ | ✓ second-precision |
| Commitments / reservations / term | ✓ | ✓ | – | ✓ `Commitment` |
| Domain TLD register/renew/transfer pricing | ✓ | – | – | ✓ price *purpose* on product |
| Per-IP / subnet recurring (IP projects) | ~ | ✓ | ✓ | ✓ resource-morph item |
| Coupons / promotions | ✓ | ~ | – | ✓ |
| Credit balance / credit notes / refunds | ✓ | ✓ | ✓ | ✓ |
| Tax (EU VAT, reverse-charge, compound) | ✓ | ✓ | ✓ | ✓ `EuVatResolver` |
| Consolidated / reseller / org billing | ~ | ✓ | ~ | ✓ `BillingAccount` nesting |
| External accounting handoff (lexoffice) | ~ | – | – | ✓ `InvoiceDriver` |
| Outage-safe accrual (charge ≠ invoice) | – | – | – | ✓ `Charge` ledger |

`✓` supported · `~` partial/plugin · `–` n/a or not native.

**Net:** design is a superset — covers WHMCS catalog/addon/term model, AWS
usage/dimension/reservation/consolidation model, and Hetzner hourly-cap +
included-traffic + per-IP model. Two Meteric-native edges WHMCS/AWS/Hetzner don't
expose cleanly: the **charge-vs-invoice outage guarantee** and the **pluggable
invoice driver** (lexoffice).

**Deferred (not v1, design leaves room):** blended/unblended cost reports,
savings-plan-style cross-resource commitments, marketplace/third-party billing,
affiliate/commission.

---

## 14. Open Questions

1. Double-entry ledger now, or invoices-only v1?
2. Multi-currency per *customer* — allowed, or one currency per customer?
3. Refund proration on immediate cancel — default on or off?
4. Discount stacking precedence rules — config or hardcoded?
5. Usage records: push (app reports) only, or also pull adapters?
6. Invoicing run cadence — per-customer cron, or queued on `RenewalDue`?
7. Charge batching window — one invoice per due-charge run, or accumulate
   multiple cycles before billing?
8. Lexoffice failure backoff/alert policy — retry forever, or dead-letter after N?
9. Hourly usage: trust app-pushed start/stop, or Meteric samples a heartbeat?
10. Addon group exclusivity + slot step/min/max — per-product config schema shape?
11. Bill-now checkout failure (driver down at order time) — block the order, or
    accept order + leave charges `pending` and provision anyway?
12. Postpaid credit-risk policy — deposit/credit-limit gate before provisioning,
    or pure post-bill?
13. Commitments v1 or v2? Adds term-tracking + early-termination math.
14. Consolidated billing depth — single payer→accounts, or arbitrary nesting?
15. Allowance scope — per-dimension only, or shareable pool across resources
    (Hetzner shares traffic across a project)?
16. Domain price *purposes* (register/renew/transfer) — modeled as separate
    `Price` rows with a `purpose` column, or separate products?
