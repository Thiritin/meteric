<?php

declare(strict_types=1);

namespace Meteric;

use Brick\Math\RoundingMode;
use Ibericode\Vat\Countries;
use Ibericode\Vat\Rates;
use Ibericode\Vat\Validator;
use Illuminate\Support\ServiceProvider;
use Meteric\Anchoring\PeriodPlanner;
use Meteric\Charges\ChargeAccruer;
use Meteric\Console\MarkOverdueCommand;
use Meteric\Console\RunBillingCommand;
use Meteric\Console\VatSyncCommand;
use Meteric\Contracts\Clock;
use Meteric\Contracts\InvoiceDriver;
use Meteric\Contracts\TaxResolver;
use Meteric\Invoicing\Drivers\DatabaseInvoiceDriver;
use Meteric\Invoicing\Drivers\LexofficeInvoiceDriver;
use Meteric\Pricing\CheckoutPricer;
use Meteric\Proration\Prorator;
use Meteric\Quoting\QuoteBuilder;
use Meteric\Subscriptions\ItemManager;
use Meteric\Subscriptions\OrderBuilder;
use Meteric\Subscriptions\OrderManager;
use Meteric\Subscriptions\SubscriptionBuilder;
use Meteric\Subscriptions\SubscriptionManager;
use Meteric\Support\SystemClock;
use Meteric\Tax\DatabaseTaxResolver;
use Meteric\Tax\EuVatResolver;
use Meteric\Tax\FlatRateTaxResolver;
use Meteric\Tax\IbericodeVatResolver;
use Meteric\Tax\Vies\Vies;
use Meteric\Usage\UsageRollup;

final class MetericServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/meteric.php', 'meteric');

        $this->app->singleton(Clock::class, SystemClock::class);

        $this->app->singleton(Vies::class, fn ($app) => new Vies(
            baseUrl: $app['config']['meteric.tax.vies_base_url'] ?? 'https://ec.europa.eu/taxation_customs/vies/rest-api',
            requester: array_filter([
                'countryCode' => $app['config']['meteric.tax.vies_requester.country_code'] ?? null,
                'vatNumber' => $app['config']['meteric.tax.vies_requester.vat_number'] ?? null,
            ], fn ($v): bool => $v !== null && $v !== ''),
        ));

        // Tax resolver — selected by config('meteric.tax.driver').
        $this->app->singleton(TaxResolver::class, function ($app) {
            $cfg = $app['config']['meteric.tax'];
            $key = $cfg['driver'] ?? 'ibericode';
            if (! isset($cfg['drivers'][$key])) {
                throw new \InvalidArgumentException("Unknown Meteric tax driver [{$key}]. Add it to config('meteric.tax.drivers').");
            }
            $class = $cfg['drivers'][$key];

            return match ($class) {
                DatabaseTaxResolver::class => new DatabaseTaxResolver(
                    countries: new Countries,
                    validator: ($cfg['ibericode']['verify_vat_id'] ?? true) ? new Validator : null,
                    merchantCountry: $cfg['merchant_country'] ?? 'DE',
                ),
                IbericodeVatResolver::class => $this->makeIbericodeResolver($cfg),
                FlatRateTaxResolver::class => new FlatRateTaxResolver((float) ($cfg['flat_rate'] ?? 0.19)),
                EuVatResolver::class => new EuVatResolver(merchantCountry: $cfg['merchant_country'] ?? 'DE'),
                default => $app->make($class),
            };
        });

        // ibericode Rates singleton (used by the vat-sync command).
        $this->app->singleton(Rates::class, function ($app) {
            $ib = $app['config']['meteric.tax.ibericode'] ?? [];
            $path = $ib['storage_path'] ?? storage_path('framework/cache/meteric-vat-rates.json');
            @mkdir(dirname((string) $path), 0775, true);

            return new Rates((string) $path, (int) ($ib['refresh_interval'] ?? 43200));
        });

        // Invoice driver — selected by config('meteric.invoice.driver').
        $this->app->singleton(InvoiceDriver::class, function ($app) {
            $cfg = $app['config']['meteric.invoice'];
            $key = $cfg['driver'] ?? 'database';
            if (! isset($cfg['drivers'][$key])) {
                throw new \InvalidArgumentException("Unknown Meteric invoice driver [{$key}]. Add it to config('meteric.invoice.drivers').");
            }
            $class = $cfg['drivers'][$key];

            return match ($class) {
                LexofficeInvoiceDriver::class => new LexofficeInvoiceDriver(
                    local: new DatabaseInvoiceDriver($app->make(TaxResolver::class)),
                    apiToken: (string) ($cfg['lexoffice']['api_token'] ?? ''),
                    baseUrl: $cfg['lexoffice']['base_url'] ?? 'https://api.lexoffice.io',
                    taxType: $cfg['lexoffice']['tax_type'] ?? 'net',
                    defaultCountry: $cfg['lexoffice']['country'] ?? 'DE',
                ),
                default => $app->make($class),
            };
        });

        $this->app->singleton(Prorator::class, function ($app) {
            $cfg = $app['config']['meteric'];

            return new Prorator(
                unit: $cfg['proration']['unit'] ?? 'second',
                rounding: constant(RoundingMode::class.'::'.($cfg['rounding'] ?? 'HALF_UP')),
            );
        });

        $this->app->singleton(Meteric::class, fn ($app) => new Meteric($app->make(InvoiceDriver::class)));

        $this->app->singleton(PeriodPlanner::class);
        $this->app->singleton(ChargeAccruer::class, fn ($app) => new ChargeAccruer($app->make(Prorator::class)));
        $this->app->singleton(UsageRollup::class);

        // Fresh builder per quote (stateful).
        $this->app->bind(QuoteBuilder::class, fn ($app) => new QuoteBuilder(
            clock: $app->make(Clock::class),
            prorator: $app->make(Prorator::class),
            tax: $app->make(TaxResolver::class),
            planner: $app->make(PeriodPlanner::class),
            currency: $app['config']['meteric.currency'] ?? 'EUR',
        ));

        $this->app->singleton(SubscriptionManager::class, fn ($app) => new SubscriptionManager(
            clock: $app->make(Clock::class),
            prorator: $app->make(Prorator::class),
            accruer: $app->make(ChargeAccruer::class),
        ));

        $this->app->singleton(ItemManager::class, fn ($app) => new ItemManager(
            clock: $app->make(Clock::class),
            prorator: $app->make(Prorator::class),
        ));

        // Fresh builder per subscribe (stateful).
        $this->app->bind(SubscriptionBuilder::class, fn ($app) => new SubscriptionBuilder(
            clock: $app->make(Clock::class),
            planner: $app->make(PeriodPlanner::class),
            accruer: $app->make(ChargeAccruer::class),
            defaultCurrency: $app['config']['meteric.currency'] ?? 'EUR',
        ));

        $this->app->singleton(CheckoutPricer::class, fn ($app) => new CheckoutPricer(
            planner: $app->make(PeriodPlanner::class),
            prorator: $app->make(Prorator::class),
            tax: $app->make(TaxResolver::class),
        ));

        $this->app->singleton(OrderManager::class, fn ($app) => new OrderManager(
            clock: $app->make(Clock::class),
            planner: $app->make(PeriodPlanner::class),
        ));

        // Fresh builder per order (stateful).
        $this->app->bind(OrderBuilder::class, fn ($app) => new OrderBuilder(
            clock: $app->make(Clock::class),
            pricer: $app->make(CheckoutPricer::class),
            defaultCurrency: $app['config']['meteric.currency'] ?? 'EUR',
            ttlMinutes: $app['config']['meteric.order.ttl_minutes'] ?? null,
        ));
    }

    /** @param array<string,mixed> $cfg meteric.tax config */
    private function makeIbericodeResolver(array $cfg): IbericodeVatResolver
    {
        $ib = $cfg['ibericode'] ?? [];
        $path = $ib['storage_path'] ?? storage_path('framework/cache/meteric-vat-rates.json');
        @mkdir(dirname((string) $path), 0775, true);

        return new IbericodeVatResolver(
            rates: new Rates((string) $path, (int) ($ib['refresh_interval'] ?? 43200)),
            validator: new Validator,
            countries: new Countries,
            merchantCountry: $cfg['merchant_country'] ?? 'DE',
            verifyVatId: (bool) ($ib['verify_vat_id'] ?? true),
        );
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/meteric.php' => config_path('meteric.php'),
            ], 'meteric-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'meteric-migrations');

            $this->commands([VatSyncCommand::class, MarkOverdueCommand::class, RunBillingCommand::class]);
        }

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
