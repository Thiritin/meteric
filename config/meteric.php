<?php

declare(strict_types=1);

use Meteric\Enums\BillingMode;
use Meteric\Enums\FirstPeriodPolicy;
use Meteric\Invoicing\Drivers\DatabaseInvoiceDriver;
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
    | Anchoring & first period defaults
    |--------------------------------------------------------------------------
    | Global default; overridable per subscription/product.
    */
    'anchor' => [
        'mode' => env('METERIC_ANCHOR_MODE', 'signup'),     // signup | fixed_day | fixed_dow
        'day' => env('METERIC_ANCHOR_DAY', 1),
        'first_period' => env('METERIC_FIRST_PERIOD', FirstPeriodPolicy::ProrateOnly->value),
        'default_billing_mode' => BillingMode::InAdvance->value,
    ],

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
            // 'lexoffice' => \App\Billing\LexofficeInvoiceDriver::class,
        ],
        // Mirror canonical record to DB even when a remote driver is primary.
        'mirror_to_database' => env('METERIC_INVOICE_MIRROR', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Ledger (double-entry audit)
    |--------------------------------------------------------------------------
    */
    'ledger' => [
        'enabled' => env('METERIC_LEDGER', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Schema
    |--------------------------------------------------------------------------
    | Morph key type for host references and Meteric PKs.
    */
    'schema' => [
        'prefix' => 'meteric_',
        'morph_key' => env('METERIC_MORPH_KEY', 'uuid'), // uuid | bigint
    ],
];
