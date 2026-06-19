# Meteric

[![tests](https://github.com/Thiritin/meteric/actions/workflows/tests.yml/badge.svg)](https://github.com/Thiritin/meteric/actions/workflows/tests.yml)
[![GitHub tag](https://img.shields.io/github/tag/thiritin/meteric?include_prereleases=&sort=semver&color=blue)](https://github.com/thiritin/meteric/releases/)
[![License](https://img.shields.io/badge/License-MIT-blue)](#license)
[![issues - meteric](https://img.shields.io/github/issues/thiritin/meteric)](https://github.com/thiritin/meteric/issues)

Dynamic billing for Laravel hosting systems. Subscriptions, proration, usage
metering, addons, commitments, and invoicing on PostgreSQL, built so a charge is
never billed twice and never lost when your accounting system is down.

## Documentation

Full docs are at **[thiritin.github.io/meteric](https://thiritin.github.io/meteric/)**.

Start with the [Introduction](https://thiritin.github.io/meteric/), then
[Installation](https://thiritin.github.io/meteric/guide/installation) and the
[Quickstart](https://thiritin.github.io/meteric/guide/quickstart).

## Requirements

PHP 8.3+, Laravel 12, PostgreSQL 13+.

## Installation

```bash
composer require thiritin/meteric
php artisan vendor:publish --tag=meteric-config
php artisan migrate
```

## A quick taste

```php
use Meteric\Facades\Meteric;

$sub = Meteric::subscribe()
    ->account($account)
    ->add($vpsPrice)
    ->create();

// Collect pending charges and issue an invoice through the bound driver.
$invoice = Meteric::invoicePending($account);
```

See the [docs](https://thiritin.github.io/meteric/) for subscriptions, plan
changes, usage billing, tax, and the rest.

## Testing

```bash
composer test
```

## License

MIT. See [LICENSE](LICENSE).
