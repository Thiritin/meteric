# Meteric — Database Schema (PostgreSQL)

Postgres-only by design. Leans on Postgres features that make billing safer:

- **`tstzrange` + `btree_gist` EXCLUDE constraints** — the DB itself refuses to
  bill the same service window twice for a resource. Idempotency enforced in
  storage, not just code.
- **String + CHECK for enums** (not native `CREATE TYPE`) — states/modes are
  `varchar` columns guarded by a CHECK listing the PHP enum's values. Deliberate:
  adding a value is an enum edit + a swapped CHECK, vs native enum `ALTER TYPE`
  (can't run in a transaction, values can't be removed). Same safety, easy alters.
- **Partial indexes** — the invoicing run scans only `WHERE state = 'pending'`.
- **`jsonb` (+ GIN)** — tier tables, meter config, metadata without side tables.
- **`numeric`** — fractional usage (hours, GB) and **sub-cent unit rates**
  (`0.001/GB`) without float error; stored money *amounts* stay integer `bigint`
  minor units, rounded to currency scale at the charge boundary.
- **`gen_random_uuid()`** PKs (core in PG13+).
- **Triggers** — enforce invoice/charge immutability + `updated_at`.

Conventions: all tables `meteric_*`, money = `*_minor BIGINT` + `currency
CHAR(3)`, time = `timestamptz`, periods = `tstzrange [start, end)`. Morphs =
`*_type TEXT` + `*_id` (uuid or bigint via text; host PK type configurable).

> **Scope.** Six tables that ship in the migrations have no DDL here yet:
> `meteric_orders`, `meteric_product_options`, `meteric_product_option_values`,
> `meteric_tax_rates`, `meteric_tax_registrations`, `meteric_invoice_numbers`.
> Read `database/migrations/` for those.

### Money precision rule

Two distinct things, two storage types:

| Kind | Example | Column | Type |
|------|---------|--------|------|
| **Amount** (stored balance) | a €9.99 charge, an invoice total | `*_minor` | `BIGINT` integer minor units |
| **Rate** (multiplier) | €0.001/GB, €0.0000125/CPU-sec | `rate`, `unit_rate` | `NUMERIC(20,8)` major units |

`amount = round(quantity × rate)` to the currency's minor scale (2 decimals for
EUR, 0 for JPY, 3 for BHD) — done in `BigDecimal`, rounded **once** at the
charge/line boundary (`MoneyMath::fromRate()`). So every persisted amount is
already 2-decimal-clean and `invoice.total = Σ line amounts` reconciles exactly.
Rates keep full precision; only the billable amount is rounded.

---

## 0. Extensions, enums, helpers

> **Implementation note.** The DDL in this doc is the *logical* schema. The real
> migrations use Laravel's Blueprint + `tpetry/laravel-postgresql-enhanced`
> (for `tstzrange`, partial/GIN/expression indexes, identity) with thin raw
> `DB::statement` only for the GiST `EXCLUDE` constraint and the immutability
> triggers. Enum-typed columns shown below as `meteric_*_state` etc. are actually
> `varchar` + a `CHECK` listing the PHP enum's values (`Meteric\Support\Pg::enumCheck`).

```sql
CREATE EXTENSION IF NOT EXISTS btree_gist;  -- EXCLUDE: scalar = + range &&
```

**Enums = string + CHECK** (not native `CREATE TYPE`). Example for charge state:

```sql
state varchar NOT NULL DEFAULT 'pending'
  CONSTRAINT meteric_charges_state_check
  CHECK (state IN ('pending','invoiced','settled','void'))
```

Why not native enum types: `ALTER TYPE ... ADD VALUE` cannot run inside a
transaction (breaks Laravel migrations) and values cannot be removed/reordered
without recreating the type and rewriting every dependent column. A CHECK is
dropped/added trivially. The PHP backed-enum cast gives type safety in code; the
CHECK gives integrity in the DB. Allowed-value lists are generated from the PHP
enums so the two never drift.

Timestamps (`created_at`/`updated_at`) are managed by Eloquent — no DB trigger.
Immutability of issued invoices/lines is enforced by triggers (see §8).

---

## 1. Accounts & catalog

### meteric_billing_accounts
Payer node. Nestable for consolidated / reseller invoicing (§13 of DESIGN).

```sql
CREATE TABLE meteric_billing_accounts (
  id            uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  parent_id     uuid REFERENCES meteric_billing_accounts(id) ON DELETE RESTRICT,
  owner_type    text NOT NULL,                 -- morph → app User/Org
  owner_id      text NOT NULL,
  currency      char(3) NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
  tax_profile   jsonb NOT NULL DEFAULT '{}',   -- country, vat_id, b2b flag, exempt
  balance_minor bigint NOT NULL DEFAULT 0,     -- account credit (can be negative)
  metadata      jsonb NOT NULL DEFAULT '{}',
  created_at    timestamptz NOT NULL DEFAULT now(),
  updated_at    timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX meteric_accounts_owner_idx  ON meteric_billing_accounts (owner_type, owner_id);
CREATE INDEX meteric_accounts_parent_idx ON meteric_billing_accounts (parent_id);
```

### meteric_products
Catalog entry. Morphs to the concrete plan (VpsPlan, Tld, …).

```sql
CREATE TABLE meteric_products (
  id             uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  billable_type  text,                          -- morph → host plan (nullable for synthetic)
  billable_id    text,
  type           text NOT NULL,                 -- 'vps' | 'domain' | 'webhosting' | 'cloud' | 'gameserver' | 'ip' | 'addon' | ...
  slug           text NOT NULL UNIQUE,
  name           text NOT NULL,
  pricing_model  meteric_pricing_model NOT NULL,
  is_proratable  boolean NOT NULL DEFAULT true,
  config         jsonb NOT NULL DEFAULT '{}',   -- model-specific (slot step/min/max, subnet sizing, …)
  active         boolean NOT NULL DEFAULT true,
  metadata       jsonb NOT NULL DEFAULT '{}',
  created_at     timestamptz NOT NULL DEFAULT now(),
  updated_at     timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX meteric_products_billable_idx ON meteric_products (billable_type, billable_id);
CREATE INDEX meteric_products_type_idx     ON meteric_products (type) WHERE active;
```

### meteric_prices
Versioned price. Carries recurrence, timing, purpose, caps, tiers.

```sql
CREATE TABLE meteric_prices (
  id              uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  product_id      uuid NOT NULL REFERENCES meteric_products(id) ON DELETE CASCADE,
  currency        char(3) NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
  amount_minor    bigint NOT NULL CHECK (amount_minor >= 0),     -- unit/base amount
  purpose         meteric_price_purpose NOT NULL DEFAULT 'recurring',
  pricing_model   meteric_pricing_model NOT NULL,
  interval        meteric_interval,                              -- NULL ⇒ one-off
  interval_count  integer CHECK (interval_count IS NULL OR interval_count > 0),
  billing_mode    meteric_billing_mode NOT NULL DEFAULT 'in_advance',
  setup_fee_minor bigint NOT NULL DEFAULT 0 CHECK (setup_fee_minor >= 0),
  cap_minor       bigint CHECK (cap_minor IS NULL OR cap_minor >= 0),   -- hourly monthly cap
  min_charge_minor bigint NOT NULL DEFAULT 0,
  tiers           jsonb NOT NULL DEFAULT '[]',  -- [{ up_to: 100, unit_minor: 5 }, { up_to: null, unit_minor: 3 }]
  tax_inclusive   boolean NOT NULL DEFAULT false,
  valid_from      timestamptz NOT NULL DEFAULT now(),
  valid_to        timestamptz,                  -- NULL = current; old rows kept for grandfathering
  metadata        jsonb NOT NULL DEFAULT '{}',
  created_at      timestamptz NOT NULL DEFAULT now(),
  updated_at      timestamptz NOT NULL DEFAULT now(),
  CHECK (interval IS NOT NULL OR purpose IN ('one_off','setup','register'))
);
-- One active price per (product, currency, purpose) at any instant.
CREATE INDEX meteric_prices_lookup_idx ON meteric_prices (product_id, currency, purpose)
  WHERE valid_to IS NULL;
CREATE INDEX meteric_prices_tiers_gin  ON meteric_prices USING gin (tiers);
```

### meteric_meter_dimensions
AWS-style: many usage dimensions per product, each priced + optional allowance.

```sql
CREATE TABLE meteric_meter_dimensions (
  id             uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  product_id     uuid NOT NULL REFERENCES meteric_products(id) ON DELETE CASCADE,
  key            text NOT NULL,                 -- 'cpu_hour' | 'traffic_out_gb' | 'iops' | 'slot_hour'
  unit           text NOT NULL,                 -- display unit
  aggregation    meteric_aggregation NOT NULL DEFAULT 'sum',
  rate           numeric(20,8) NOT NULL CHECK (rate >= 0),  -- per unit, or per block when block_size is set
  block_size     numeric(20,6),                     -- bill per block of this many units (ceil); NULL = per unit
  currency       char(3) NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
  included_qty   numeric(20,6) NOT NULL DEFAULT 0,   -- free allowance per cycle
  cap_minor      bigint,                            -- per-dimension cap
  tiers          jsonb NOT NULL DEFAULT '[]',
  created_at     timestamptz NOT NULL DEFAULT now(),
  UNIQUE (product_id, key)
);
```

---

## 2. Subscriptions, items, addons, options

### meteric_subscriptions

```sql
CREATE TABLE meteric_subscriptions (
  id               uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  account_id       uuid NOT NULL REFERENCES meteric_billing_accounts(id) ON DELETE RESTRICT,
  customer_type    text NOT NULL,               -- morph → app billable party
  customer_id      text NOT NULL,
  currency         char(3) NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
  state            meteric_sub_state NOT NULL DEFAULT 'incomplete',
  anchor_mode      meteric_anchor_mode NOT NULL DEFAULT 'signup',
  anchor_day       smallint CHECK (anchor_day BETWEEN 1 AND 31),   -- for fixed_day
  first_period     meteric_first_period NOT NULL DEFAULT 'prorate_only',
  current_period   tstzrange,                   -- the subscription-level window
  trial_end        timestamptz,
  canceled_at      timestamptz,
  cancel_at        timestamptz,                 -- scheduled (period-end) cancel
  version          integer NOT NULL DEFAULT 0,  -- optimistic lock
  metadata         jsonb NOT NULL DEFAULT '{}',
  created_at       timestamptz NOT NULL DEFAULT now(),
  updated_at       timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX meteric_subs_account_idx  ON meteric_subscriptions (account_id);
CREATE INDEX meteric_subs_customer_idx ON meteric_subscriptions (customer_type, customer_id);
CREATE INDEX meteric_subs_due_idx      ON meteric_subscriptions (upper(current_period))
  WHERE state IN ('active','trialing','past_due');
CREATE TRIGGER meteric_subs_touch BEFORE UPDATE ON meteric_subscriptions
  FOR EACH ROW EXECUTE FUNCTION meteric_touch_updated_at();
```

### meteric_subscription_items
Base line. Morphs to the provisioned resource (a specific VPS, IP, gameserver).

```sql
CREATE TABLE meteric_subscription_items (
  id               uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  subscription_id  uuid NOT NULL REFERENCES meteric_subscriptions(id) ON DELETE CASCADE,
  product_id       uuid NOT NULL REFERENCES meteric_products(id) ON DELETE RESTRICT,
  price_id         uuid NOT NULL REFERENCES meteric_prices(id) ON DELETE RESTRICT,
  resource_type    text,                        -- morph → running resource
  resource_id      text,
  quantity         numeric(20,6) NOT NULL DEFAULT 1 CHECK (quantity >= 0),
  billing_mode     meteric_billing_mode,        -- NULL ⇒ inherit price
  state            meteric_item_state NOT NULL DEFAULT 'pending',
  current_period   tstzrange,                   -- per-item window (items may differ)
  activated_at     timestamptz,
  ends_at          timestamptz,                 -- resource destroyed / item closed
  pending_change   jsonb,                       -- scheduled plan change (price_id, apply_at)
  version          integer NOT NULL DEFAULT 0,
  metadata         jsonb NOT NULL DEFAULT '{}',
  created_at       timestamptz NOT NULL DEFAULT now(),
  updated_at       timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX meteric_items_sub_idx      ON meteric_subscription_items (subscription_id);
CREATE INDEX meteric_items_resource_idx ON meteric_subscription_items (resource_type, resource_id);
CREATE INDEX meteric_items_due_idx      ON meteric_subscription_items (upper(current_period))
  WHERE state = 'active';
```

### meteric_addons
Bookable extra on an item. `group_key` enforces mutual exclusivity (one RAM tier).

```sql
CREATE TABLE meteric_addons (
  id        uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  item_id   uuid NOT NULL REFERENCES meteric_subscription_items(id) ON DELETE CASCADE,
  product_id uuid NOT NULL REFERENCES meteric_products(id) ON DELETE RESTRICT,
  price_id  uuid NOT NULL REFERENCES meteric_prices(id) ON DELETE RESTRICT,
  group_key text,                               -- e.g. 'ram'; one active per group
  quantity  numeric(20,6) NOT NULL DEFAULT 1,
  state     meteric_item_state NOT NULL DEFAULT 'active',
  metadata  jsonb NOT NULL DEFAULT '{}',
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now()
);
-- At most one active addon per group per item:
CREATE UNIQUE INDEX meteric_addons_group_uq ON meteric_addons (item_id, group_key)
  WHERE state = 'active' AND group_key IS NOT NULL;
```

### meteric_item_options
Configurable option of an item (qty / choice / toggle).

```sql
CREATE TABLE meteric_item_options (
  id        uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  item_id   uuid NOT NULL REFERENCES meteric_subscription_items(id) ON DELETE CASCADE,
  key       text NOT NULL,                      -- 'slots' | 'location' | 'backups'
  type      meteric_option_type NOT NULL,
  value     text NOT NULL,                      -- '16' | 'eu-central' | 'true'
  price_id  uuid REFERENCES meteric_prices(id) ON DELETE RESTRICT,
  quantity  numeric(20,6) NOT NULL DEFAULT 1,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (item_id, key)
);
```

---

## 3. Usage & periods (the no-double-bill core)

### meteric_usage_records
Raw reported usage. Idempotent ingest via unique key.

```sql
CREATE TABLE meteric_usage_records (
  id            uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  item_id       uuid NOT NULL REFERENCES meteric_subscription_items(id) ON DELETE CASCADE,
  dimension_id  uuid NOT NULL REFERENCES meteric_meter_dimensions(id) ON DELETE RESTRICT,
  quantity      numeric(20,6) NOT NULL CHECK (quantity >= 0),
  occurred_at   timestamptz NOT NULL,
  window        tstzrange,                      -- for interval-sampled records
  source        text,                           -- 'openstack' | 'agent' | 'manual'
  idempotency_key text NOT NULL,
  charge_id     uuid,                           -- set once rolled up
  created_at    timestamptz NOT NULL DEFAULT now(),
  UNIQUE (idempotency_key)
);
CREATE INDEX meteric_usage_unbilled_idx ON meteric_usage_records (item_id, dimension_id, occurred_at)
  WHERE charge_id IS NULL;
```

### meteric_billing_periods
**The double-bill guard.** One row per fully-billed window per item+dimension.
A GiST EXCLUDE constraint makes overlapping windows physically impossible.

```sql
CREATE TABLE meteric_billing_periods (
  id            uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  item_id       uuid NOT NULL REFERENCES meteric_subscription_items(id) ON DELETE CASCADE,
  dimension_id  uuid,                           -- NULL = base recurring; else metered dimension
  covers        tstzrange NOT NULL,
  charge_id     uuid,                           -- the charge that billed it
  created_at    timestamptz NOT NULL DEFAULT now(),
  CONSTRAINT meteric_period_valid CHECK (lower(covers) < upper(covers)),
  -- No two billed windows for the same item+dimension may overlap:
  CONSTRAINT meteric_no_overlap EXCLUDE USING gist (
    item_id WITH =,
    COALESCE(dimension_id, '00000000-0000-0000-0000-000000000000'::uuid) WITH =,
    covers  WITH &&
  )
);
```

> This is the Postgres payoff: even a buggy/retried renewal job **cannot** bill a
> period twice — the second insert raises `exclusion_violation`. Code treats that
> as "already billed, skip."

---

## 4. Charges (source of truth)

### meteric_charges
Money owed, decoupled from invoicing (DESIGN §2.5).

```sql
CREATE TABLE meteric_charges (
  id              uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  account_id      uuid NOT NULL REFERENCES meteric_billing_accounts(id) ON DELETE RESTRICT,
  subscription_id uuid REFERENCES meteric_subscriptions(id) ON DELETE SET NULL,
  origin_type     text NOT NULL,               -- morph: item | usage | proration | one_off | setup
  origin_id       text NOT NULL,
  dimension_id    uuid REFERENCES meteric_meter_dimensions(id),
  kind            meteric_line_kind NOT NULL,
  billing_mode    meteric_billing_mode NOT NULL,
  state           meteric_charge_state NOT NULL DEFAULT 'pending',
  title           text,                        -- line name (product + resource)
  "group"         text,                        -- invoice section heading (Domains, Usage, …)
  line_group      uuid,                        -- owning subscription item: links a base line to its options/addons
  description     text,                        -- multi-line detail (period, usage breakdown)
  quantity        numeric(20,6) NOT NULL DEFAULT 1,
  unit            text,                        -- quantity unit label (month, hours, GB)
  unit_minor      bigint,                      -- integer unit price (fixed/per-unit)
  unit_rate       numeric(20,8),               -- sub-cent unit rate (usage), for display
  amount_minor    bigint NOT NULL,             -- rounded billable amount; signed (negative = credit)
  currency        char(3) NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
  covers          tstzrange,                   -- service period billed
  idempotency_key text NOT NULL,
  metadata        jsonb NOT NULL DEFAULT '{}',
  version         integer NOT NULL DEFAULT 0,
  created_at      timestamptz NOT NULL DEFAULT now(),
  updated_at      timestamptz NOT NULL DEFAULT now(),
  deleted_at      timestamptz,                 -- soft delete: discard without losing the record
  UNIQUE (idempotency_key)
);
-- The invoicing run's hot path — only live pending rows. The charge<->invoice
-- link lives on meteric_invoice_lines.charge_id, not here.
CREATE INDEX meteric_charges_pending_idx ON meteric_charges (account_id, currency)
  WHERE state = 'pending' AND deleted_at IS NULL;
CREATE INDEX meteric_charges_origin_idx  ON meteric_charges (origin_type, origin_id);
CREATE INDEX meteric_charges_line_group_idx ON meteric_charges (line_group);
CREATE TRIGGER meteric_charges_touch BEFORE UPDATE ON meteric_charges
  FOR EACH ROW EXECUTE FUNCTION meteric_touch_updated_at();
```

---

## 5. Invoices, lines, credit notes, payments

### meteric_invoices

```sql
CREATE TABLE meteric_invoices (
  id             uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  account_id     uuid NOT NULL REFERENCES meteric_billing_accounts(id) ON DELETE RESTRICT,
  customer_type  text NOT NULL,
  customer_id    text NOT NULL,
  number         text,                          -- assigned at issue; null while draft
  driver         text NOT NULL DEFAULT 'database',
  external_id    text,                          -- lexoffice voucher id
  external_url   text,
  state          meteric_invoice_state NOT NULL DEFAULT 'draft',
  currency       char(3) NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
  subtotal_minor bigint NOT NULL DEFAULT 0,
  tax_minor      bigint NOT NULL DEFAULT 0,
  total_minor    bigint NOT NULL DEFAULT 0,
  paid_minor     bigint NOT NULL DEFAULT 0,
  issued_at      timestamptz,
  due_at         timestamptz,
  paid_at        timestamptz,
  idempotency_key text,                         -- batch key (charge set) for safe retry
  metadata       jsonb NOT NULL DEFAULT '{}',
  version        integer NOT NULL DEFAULT 0,
  created_at     timestamptz NOT NULL DEFAULT now(),
  updated_at     timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX meteric_invoices_number_uq  ON meteric_invoices (number) WHERE number IS NOT NULL;
CREATE UNIQUE INDEX meteric_invoices_batch_uq   ON meteric_invoices (idempotency_key) WHERE idempotency_key IS NOT NULL;
CREATE INDEX meteric_invoices_account_idx ON meteric_invoices (account_id, state);
CREATE TRIGGER meteric_invoices_touch BEFORE UPDATE ON meteric_invoices
  FOR EACH ROW EXECUTE FUNCTION meteric_touch_updated_at();
-- Lock lines once issued (state ≠ draft): block any UPDATE to financial cols via app guard;
-- hard guard on lines table below.
```

### meteric_invoice_lines
Immutable snapshot and the document unit. Carries its own `covers` window
(prepaid vs arrears differ). `charge_id` links it to the charge it bills (null for
a manual line); `parent_id` nests a sub-line under its parent product line.

```sql
CREATE TABLE meteric_invoice_lines (
  id            uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  invoice_id    uuid NOT NULL REFERENCES meteric_invoices(id) ON DELETE CASCADE,
  charge_id     uuid REFERENCES meteric_charges(id) ON DELETE SET NULL,
  parent_id     uuid REFERENCES meteric_invoice_lines(id) ON DELETE CASCADE,  -- sub-line
  kind          meteric_line_kind NOT NULL,
  title         text,                          -- line name (product + resource)
  "group"       text,                          -- invoice section heading (Domains, Usage, …)
  line_group    uuid,                          -- owning subscription item: links a base line to its options/addons
  description   text,                          -- multi-line detail (period, usage breakdown)
  quantity      numeric(20,6) NOT NULL DEFAULT 1,
  unit          text,                          -- quantity unit label (month, hours, GB)
  unit_minor    bigint,                        -- integer unit price (fixed/per-unit)
  unit_rate     numeric(20,8),                 -- sub-cent unit rate (usage), for display
  amount_minor  bigint NOT NULL,               -- net, signed; own amount only (children carry theirs)
  tax_rate      numeric(6,4) NOT NULL DEFAULT 0,   -- e.g. 0.1900
  tax_minor     bigint NOT NULL DEFAULT 0,      -- per-line tax
  tax_label     text,                          -- 'USt 19%' | 'Reverse charge'
  currency      char(3) NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
  covers        tstzrange,                     -- service period this line bills
  dimension_id  uuid,
  sort          integer NOT NULL DEFAULT 0,
  metadata      jsonb NOT NULL DEFAULT '{}'
);
CREATE INDEX meteric_lines_invoice_idx ON meteric_invoice_lines (invoice_id, sort);
CREATE INDEX meteric_lines_parent_idx  ON meteric_invoice_lines (parent_id);
```

### meteric_credit_notes (+ lines mirror invoice lines)

```sql
CREATE TABLE meteric_credit_notes (
  id           uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  invoice_id   uuid NOT NULL REFERENCES meteric_invoices(id) ON DELETE RESTRICT,
  number       text,
  driver       text NOT NULL DEFAULT 'database',
  external_id  text,
  state        meteric_credit_state NOT NULL DEFAULT 'draft',
  reason       text,
  amount_minor bigint NOT NULL,               -- positive magnitude credited
  tax_minor    bigint NOT NULL DEFAULT 0,
  currency     char(3) NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
  issued_at    timestamptz,
  metadata     jsonb NOT NULL DEFAULT '{}',
  created_at   timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX meteric_credit_number_uq ON meteric_credit_notes (number) WHERE number IS NOT NULL;
```

### meteric_payments + allocations
Inbound (app calls `recordPayment`). Allocation supports partial / split.

```sql
CREATE TABLE meteric_payments (
  id           uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  account_id   uuid NOT NULL REFERENCES meteric_billing_accounts(id) ON DELETE RESTRICT,
  amount_minor bigint NOT NULL CHECK (amount_minor > 0),
  currency     char(3) NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
  reference    text,                            -- gateway id (pi_..., paypal txn)
  received_at  timestamptz NOT NULL DEFAULT now(),
  metadata     jsonb NOT NULL DEFAULT '{}',
  UNIQUE (reference)
);
CREATE TABLE meteric_payment_allocations (
  id           uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  payment_id   uuid NOT NULL REFERENCES meteric_payments(id) ON DELETE CASCADE,
  invoice_id   uuid NOT NULL REFERENCES meteric_invoices(id) ON DELETE RESTRICT,
  amount_minor bigint NOT NULL CHECK (amount_minor > 0),
  UNIQUE (payment_id, invoice_id)
);
```

---

## 7. Ledger (double-entry audit)

The table is provisioned by migration but is not yet written to by any Meteric
code path. Every money movement = balanced debit/credit rows.

```sql
CREATE TABLE meteric_ledger (
  id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  account_id    uuid NOT NULL REFERENCES meteric_billing_accounts(id) ON DELETE RESTRICT,
  txn_id        uuid NOT NULL,                  -- groups balanced rows
  entry         text NOT NULL,                  -- 'charge' | 'invoice' | 'payment' | 'credit' | 'refund'
  debit_minor   bigint NOT NULL DEFAULT 0,
  credit_minor  bigint NOT NULL DEFAULT 0,
  currency      char(3) NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
  ref_type      text, ref_id text,              -- morph → charge/invoice/payment
  posted_at     timestamptz NOT NULL DEFAULT now(),
  CHECK (debit_minor = 0 OR credit_minor = 0)
);
CREATE INDEX meteric_ledger_account_idx ON meteric_ledger (account_id, posted_at);
CREATE INDEX meteric_ledger_txn_idx     ON meteric_ledger (txn_id);
```

---

## 8. Immutability triggers

```sql
-- Issued invoices: block deletes; block updates to financial columns.
CREATE OR REPLACE FUNCTION meteric_invoice_immutable() RETURNS trigger AS $$
BEGIN
  IF OLD.state <> 'draft' THEN
    IF TG_OP = 'DELETE' THEN
      RAISE EXCEPTION 'meteric: issued invoice % cannot be deleted', OLD.id;
    END IF;
    IF NEW.currency <> OLD.currency OR NEW.subtotal_minor <> OLD.subtotal_minor
       OR NEW.total_minor <> OLD.total_minor OR NEW.tax_minor <> OLD.tax_minor THEN
      RAISE EXCEPTION 'meteric: issued invoice % financials are immutable', OLD.id;
    END IF;
  END IF;
  RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER meteric_invoices_immutable BEFORE UPDATE OR DELETE ON meteric_invoices
  FOR EACH ROW EXECUTE FUNCTION meteric_invoice_immutable();

-- Invoice lines of a non-draft invoice are frozen entirely.
CREATE OR REPLACE FUNCTION meteric_line_immutable() RETURNS trigger AS $$
DECLARE st meteric_invoice_state;
BEGIN
  SELECT state INTO st FROM meteric_invoices WHERE id = COALESCE(NEW.invoice_id, OLD.invoice_id);
  IF st <> 'draft' THEN
    RAISE EXCEPTION 'meteric: lines of issued invoice are immutable';
  END IF;
  RETURN COALESCE(NEW, OLD);
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER meteric_lines_immutable BEFORE INSERT OR UPDATE OR DELETE ON meteric_invoice_lines
  FOR EACH ROW EXECUTE FUNCTION meteric_line_immutable();
```

---

## 9. Eloquent models

Each table maps to one model in `src/Models/`, all extending `MetericModel`.
Relations and helper methods live there and change with the code, so this doc
does not mirror them — read `src/Models/` for the authoritative list of models,
their relations, and their public methods.

**Quote** (no table) — `QuoteBuilder` returns a `Quote` value object with
`->dueNow()`, `->recurring()`, `->lines()`, `->toArray()`/`->toJson()` for
checkout frontends (DESIGN §3.6).

Money everywhere = `Brick\Money\Money` cast from `*_minor` + `currency` via a
custom Eloquent cast (`MoneyCast`). Ranges cast to a `Period` value object
(`PeriodCast`) wrapping `tstzrange`.

---

## 10. Migration & extensibility notes

- Table prefix is configurable in `config/meteric.php` (`schema.prefix`, applied
  by `Meteric\Support\Pg::table()` to models and migrations alike). Set it once,
  before migrating. Constraint and index identifiers keep a fixed `meteric_`
  spelling and are not affected by it.
- Morph columns (`owner_type`/`owner_id`, `customer_*`, `resource_*`,
  `origin_*`, `billable_*`) are plain `varchar` on both halves, so a host PK of
  any type (uuid, bigint, ulid) stores as text. There is no config key for the
  morph key type.
- Enums are `varchar` + a named CHECK constraint generated from the PHP backed
  enum (`Meteric\Support\Pg::enumCheck`) — no native `CREATE TYPE`, so no
  `ALTER TYPE ... ADD VALUE`. Widening an enum means adding the case in PHP and
  swapping the CHECK (drop + add), which runs inside a normal transactional
  migration.
- All `jsonb` config columns give the open-source consumer room to extend
  pricing/meter behavior without schema changes.
- `btree_gist` is the only hard extension dep; standard. `gen_random_uuid()` is
  core since PG 13, so `pgcrypto` is not needed.
- Money composite domain (`CREATE DOMAIN`) intentionally **not** used — keeping
  `*_minor`/`currency` as plain columns maps cleanly to Eloquent casts and to
  external drivers (lexoffice).

