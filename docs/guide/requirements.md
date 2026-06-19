# Requirements

- PHP 8.5+
- Laravel 12
- PostgreSQL 13+

Meteric is PostgreSQL-only by design. It uses `tstzrange`, `btree_gist`,
`pgcrypto`, and a GiST `EXCLUDE` constraint to guarantee no service window is
billed twice. There is no MySQL or SQLite fallback for those guarantees, so the
test suite and migrations target Postgres.

## PostgreSQL extensions

The migrations enable the extensions they need (`btree_gist`, `pgcrypto`). The
database role running migrations must be allowed to `CREATE EXTENSION`. On
managed Postgres this is usually granted; on a locked-down instance, have a
superuser create the extensions once before you migrate:

```sql
CREATE EXTENSION IF NOT EXISTS btree_gist;
CREATE EXTENSION IF NOT EXISTS pgcrypto;
```

## Money and precision

Money is handled by `brick/money` as integer minor units. Per-unit rates that go
below a cent (usage, hourly) are stored as `numeric(20,8)` strings and never
touched as floats. You do not configure this; it is how the package stores
money everywhere.
