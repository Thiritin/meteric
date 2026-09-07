<?php

declare(strict_types=1);

use Meteric\Invoicing\Drivers\DatabaseInvoiceDriver;
use Meteric\Invoicing\Drivers\LexofficeInvoiceDriver;
use Meteric\Tax\DatabaseTaxResolver;
use Meteric\Tax\EuVatResolver;
use Meteric\Tax\FlatRateTaxResolver;
use Meteric\Tax\IbericodeVatResolver;
use Meteric\Tax\NullTaxResolver;

return [

    /*
    |--------------------------------------------------------------------------
    | Default currency
    |--------------------------------------------------------------------------
    */
    'currency' => env('METERIC_CURRENCY', 'EUR'),

    /*
    |--------------------------------------------------------------------------
    | Proration
    |--------------------------------------------------------------------------
    | Unit used to compute proration ratios. 'second' is the most precise and
    | DST/leap safe. 'day' rounds to whole days.
    */
    'proration' => [
        'unit' => env('METERIC_PRORATION_UNIT', 'second'), // second | day
    ],

    /*
    |--------------------------------------------------------------------------
    | Rounding
    |--------------------------------------------------------------------------
    | Applied per line; invoice total = sum of line totals so it reconciles.
    | One of brick/math RoundingMode names.
    */
    'rounding' => env('METERIC_ROUNDING', 'HALF_UP'),

    /*
    |--------------------------------------------------------------------------
    | Drivers
    |--------------------------------------------------------------------------
    | Invoice emission + tax resolution are swappable. Bind your own class to
    | integrate lexoffice, EU VAT, etc.
    */
    'tax' => [
        // database  = configurable multi-jurisdiction rate table + registrations
        //             (default; EU rows fed by ibericode, CH/UK/… added manually)
        // ibericode = live EU-only rates + VIES
        // eu_vat    = static offline EU fallback
        // flat / null = testing
        'driver' => env('METERIC_TAX_DRIVER', 'database'),
        'drivers' => [
            'database' => DatabaseTaxResolver::class,
            'ibericode' => IbericodeVatResolver::class,
            'eu_vat' => EuVatResolver::class,
            'flat' => FlatRateTaxResolver::class,
            'null' => NullTaxResolver::class,
        ],
        'flat_rate' => env('METERIC_TAX_FLAT_RATE', 0.19),
        'merchant_country' => env('METERIC_MERCHANT_COUNTRY', 'DE'),

        // Qualified VIES endpoint (Meteric::viesCheck): EU VAT validation with
        // trader name/address match flags. Override only for a proxy or a mock.
        'vies_base_url' => env('METERIC_VIES_URL', 'https://ec.europa.eu/taxation_customs/vies/rest-api'),

        // Your own VAT id, sent with a qualified VIES check so VIES returns a
        // consultation number (an audit receipt). A per-call requester overrides.
        'vies_requester' => [
            'country_code' => env('METERIC_VIES_REQUESTER_COUNTRY'),
            'vat_number' => env('METERIC_VIES_REQUESTER_VAT'),
        ],

        // ibericode driver settings
        'ibericode' => [
            // Writable path for the auto-refreshed rates cache.
            'storage_path' => env('METERIC_VAT_RATES_PATH', storage_path('framework/cache/meteric-vat-rates.json')),
            'refresh_interval' => (int) env('METERIC_VAT_REFRESH', 12 * 3600), // seconds
            // Verify VAT ids against VIES before reverse-charging. Off ⇒ trust presence.
            'verify_vat_id' => filter_var(env('METERIC_VERIFY_VAT_ID', true), FILTER_VALIDATE_BOOLEAN),
        ],
    ],

    'invoice' => [
        'driver' => env('METERIC_INVOICE_DRIVER', 'database'),
        'drivers' => [
            'database' => DatabaseInvoiceDriver::class,
            'lexoffice' => LexofficeInvoiceDriver::class,
        ],

        // Days after issue an invoice is due. meteric:mark-overdue uses this.
        'net_days' => (int) env('METERIC_INVOICE_NET_DAYS', 14),

        // Day of the month a collective account is invoiced on, for an account
        // that names no day of its own. Short months clamp to their last day.
        'collection_day' => (int) env('METERIC_INVOICE_COLLECTION_DAY', 1),

        // Lexware Office (lexoffice) driver settings.
        'lexoffice' => [
            'api_token' => env('METERIC_LEXOFFICE_TOKEN'),
            'base_url' => env('METERIC_LEXOFFICE_BASE_URL', 'https://api.lexoffice.io'),
            'tax_type' => env('METERIC_LEXOFFICE_TAX_TYPE', 'net'), // net | gross | vatfree
            'country' => env('METERIC_LEXOFFICE_COUNTRY', 'DE'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Subscriptions
    |--------------------------------------------------------------------------
    | consumer_notice_cap is the longest notice a buyer whose billing account is
    | marked BuyerType::Consumer can be held to, whatever `cancel_notice_days`
    | the product carries. Null, the default, caps nothing. Several consumer
    | protection regimes write such a ceiling (German BGB 309 Nr. 9 and the AGB
    | drawn from it put it at one month), so it is a calendar interval rather
    | than a day count: "one month" before the 1st of March is not the same
    | number of days as before the 1st of April, and a day count would hold a
    | consumer to a longer notice in the short months.
    |
    | Any relative expression CarbonInterval::make() understands: '1 month',
    | 'P1M', '30 days'. An account with no buyer type is capped by nothing.
    */
    'subscriptions' => [
        'consumer_notice_cap' => env('METERIC_CONSUMER_NOTICE_CAP'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Orders (persisted, immutable)
    |--------------------------------------------------------------------------
    | Default minutes a pending order stays open before the meteric:run sweep
    | expires it (1 day). Set to 0 to disable expiry. Override per order with
    | ->expiresIn($minutes) on the builder.
    */
    'order' => [
        'ttl_minutes' => (int) env('METERIC_CHECKOUT_TTL', 1440),
    ],

    /*
    |--------------------------------------------------------------------------
    | Schema
    |--------------------------------------------------------------------------
    */
    'schema' => [
        // Applied to every Meteric table name (models + migrations). Set this
        // ONCE before running migrations; changing it after migrating orphans
        // the existing tables. '' means no prefix (e.g. plain `subscriptions`).
        // Hand-named constraints and indexes (e.g. `meteric_subs_due_idx`)
        // keep a fixed `meteric_` spelling, but auto-derived enum and currency
        // CHECK names follow the prefixed table name.
        'prefix' => 'meteric_',
    ],
];
