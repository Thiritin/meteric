<?php

declare(strict_types=1);

use Billify\Enums\BillingMode;
use Billify\Enums\FirstPeriodPolicy;
use Billify\Invoicing\Drivers\DatabaseInvoiceDriver;
use Billify\Tax\DatabaseTaxResolver;
use Billify\Tax\EuVatResolver;
use Billify\Tax\FlatRateTaxResolver;
use Billify\Tax\IbericodeVatResolver;
use Billify\Tax\NullTaxResolver;

return [

    /*
    |--------------------------------------------------------------------------
    | Default currency
    |--------------------------------------------------------------------------
    */
    'currency' => env('BILLIFY_CURRENCY', 'EUR'),

    /*
    |--------------------------------------------------------------------------
    | Proration
    |--------------------------------------------------------------------------
    | Unit used to compute proration ratios. 'second' is the most precise and
    | DST/leap safe. 'day' rounds to whole days.
    */
    'proration' => [
        'unit' => env('BILLIFY_PRORATION_UNIT', 'second'), // second | day
    ],

    /*
    |--------------------------------------------------------------------------
    | Rounding
    |--------------------------------------------------------------------------
    | Applied per line; invoice total = sum of line totals so it reconciles.
    | One of brick/math RoundingMode names.
    */
    'rounding' => env('BILLIFY_ROUNDING', 'HALF_UP'),

    /*
    |--------------------------------------------------------------------------
    | Anchoring & first period defaults
    |--------------------------------------------------------------------------
    | Global default; overridable per subscription/product.
    */
    'anchor' => [
        'mode' => env('BILLIFY_ANCHOR_MODE', 'signup'),     // signup | fixed_day | fixed_dow
        'day' => env('BILLIFY_ANCHOR_DAY', 1),
        'first_period' => env('BILLIFY_FIRST_PERIOD', FirstPeriodPolicy::ProrateOnly->value),
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
        'driver' => env('BILLIFY_TAX_DRIVER', 'database'),
        'drivers' => [
            'database' => DatabaseTaxResolver::class,
            'ibericode' => IbericodeVatResolver::class,
            'eu_vat' => EuVatResolver::class,
            'flat' => FlatRateTaxResolver::class,
            'null' => NullTaxResolver::class,
        ],
        'flat_rate' => env('BILLIFY_TAX_FLAT_RATE', 0.19),
        'merchant_country' => env('BILLIFY_MERCHANT_COUNTRY', 'DE'),

        // ibericode driver settings
        'ibericode' => [
            // Writable path for the auto-refreshed rates cache.
            'storage_path' => env('BILLIFY_VAT_RATES_PATH', storage_path('framework/cache/billify-vat-rates.json')),
            'refresh_interval' => (int) env('BILLIFY_VAT_REFRESH', 12 * 3600), // seconds
            // Verify VAT ids against VIES before reverse-charging. Off ⇒ trust presence.
            'verify_vat_id' => filter_var(env('BILLIFY_VERIFY_VAT_ID', true), FILTER_VALIDATE_BOOLEAN),
        ],
    ],

    'invoice' => [
        'driver' => env('BILLIFY_INVOICE_DRIVER', 'database'),
        'drivers' => [
            'database' => DatabaseInvoiceDriver::class,
            // 'lexoffice' => \App\Billing\LexofficeInvoiceDriver::class,
        ],
        // Mirror canonical record to DB even when a remote driver is primary.
        'mirror_to_database' => env('BILLIFY_INVOICE_MIRROR', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Ledger (double-entry audit)
    |--------------------------------------------------------------------------
    */
    'ledger' => [
        'enabled' => env('BILLIFY_LEDGER', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Schema
    |--------------------------------------------------------------------------
    | Morph key type for host references and Billify PKs.
    */
    'schema' => [
        'prefix' => 'billify_',
        'morph_key' => env('BILLIFY_MORPH_KEY', 'uuid'), // uuid | bigint
    ],
];
