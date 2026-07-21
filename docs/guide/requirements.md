# Requirements

- PHP 8.3+ with the `bcmath` extension (exact money maths, no float fallback)
- Laravel 12
- PostgreSQL 16+

Meteric is PostgreSQL-only by design. It uses `tstzrange`, `btree_gist`,
and a GiST `EXCLUDE` constraint to guarantee no service window is
billed twice. There is no MySQL or SQLite fallback for those guarantees, so the
test suite and migrations target Postgres.

## PostgreSQL extensions

The migrations enable the extension they need (`btree_gist`). The
database role running migrations must be allowed to `CREATE EXTENSION`. On
managed Postgres this is usually granted; on a locked-down instance, have a
superuser create the extensions once before you migrate:

```sql
CREATE EXTENSION IF NOT EXISTS btree_gist;
```

## Money and precision

Money is handled by `brick/money` as integer minor units. Per-unit rates that go
below a cent (usage, hourly) are stored as `numeric(20,8)` strings and never
touched as floats. You do not configure this; it is how the package stores
money everywhere.
