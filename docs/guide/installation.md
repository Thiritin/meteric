# Installation

Require the package, publish the config, and migrate.

```bash
composer require thiritin/meteric
php artisan vendor:publish --tag=meteric-config
php artisan migrate
```

The service provider (`Meteric\MetericServiceProvider`) and the `Meteric`
facade alias are registered through package discovery, so there is nothing to
add to `config/app.php`.

## What migrate creates

The migrations create the `meteric_*` tables: products, prices, billing
accounts, subscriptions and items, charges, invoices and lines, payments, credit
notes, tax rates and registrations, meter dimensions, usage records, billing
periods, and orders. Table names are prefixed `meteric_` (see
[Configuration](/guide/configuration)).

Run them against PostgreSQL. The migration step enables `btree_gist` and
and installs the GiST exclusion constraint on `meteric_billing_periods`.
If your database role cannot create extensions, see [Requirements](/guide/requirements).

## Making a model billable

Any model can own a billing account or be a subscription customer. Meteric uses
Laravel's morph relations, so there is no trait to add for the basics, you pass
your model into the builders and it stores the morph type and key.

```php
use Meteric\Facades\Meteric;

// $user is any Eloquent model. firstOrCreate resolves its billing account.
$subscription = Meteric::subscribe($user)
    ->add($price)
    ->create();
```

Pass an existing `BillingAccount` with `->account()` when you manage accounts
yourself, for example to set currency or a parent account for consolidated
billing.

Next: set up [configuration](/guide/configuration), then run through the
[quickstart](/guide/quickstart).
