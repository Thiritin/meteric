<?php

declare(strict_types=1);

namespace Meteric;

use Brick\Math\RoundingMode;
use Ibericode\Vat\Rates;
use Illuminate\Support\ServiceProvider;
use Meteric\Anchoring\PeriodPlanner;
use Meteric\Charges\ChargeAccruer;
use Meteric\Console\MarkOverdueCommand;
use Meteric\Console\RunBillingCommand;
use Meteric\Console\VatSyncCommand;
use Meteric\Contracts\Clock;
use Meteric\Contracts\InvoiceDriver;
use Meteric\Contracts\TaxResolver;
use Meteric\Invoicing\InvoiceDriverManager;
use Meteric\Invoicing\InvoiceManager;
use Meteric\Invoicing\LineComposer;
use Meteric\Pricing\CheckoutPricer;
use Meteric\Proration\Prorator;
use Meteric\Quoting\QuoteBuilder;
use Meteric\Subscriptions\ItemManager;
use Meteric\Subscriptions\OrderBuilder;
use Meteric\Subscriptions\OrderManager;
use Meteric\Subscriptions\SubscriptionBuilder;
use Meteric\Subscriptions\SubscriptionManager;
use Meteric\Support\SystemClock;
use Meteric\Tax\TaxResolverManager;
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

        // Tax resolution and invoice emission are driver-based (extend() to add
        // your own). The contracts resolve to each manager's configured driver.
        $this->app->singleton(TaxResolverManager::class);
        $this->app->singleton(TaxResolver::class, fn ($app) => $app->make(TaxResolverManager::class)->driver());

        $this->app->singleton(InvoiceDriverManager::class);
        $this->app->singleton(InvoiceDriver::class, fn ($app) => $app->make(InvoiceDriverManager::class)->driver());

        // ibericode Rates singleton (used by the vat-sync command).
        $this->app->singleton(Rates::class, function ($app) {
            $ib = $app['config']['meteric.tax.ibericode'] ?? [];
            $path = $ib['storage_path'] ?? storage_path('framework/cache/meteric-vat-rates.json');
            @mkdir(dirname((string) $path), 0775, true);

            return new Rates((string) $path, (int) ($ib['refresh_interval'] ?? 43200));
        });

        $this->app->singleton(LineComposer::class, fn ($app) => new LineComposer($app->make(TaxResolver::class)));

        $this->app->singleton(InvoiceManager::class, fn ($app) => new InvoiceManager(
            driver: $app->make(InvoiceDriver::class),
            lines: $app->make(LineComposer::class),
        ));

        $this->app->singleton(Prorator::class, function ($app) {
            $cfg = $app['config']['meteric'];

            return new Prorator(
                unit: $cfg['proration']['unit'] ?? 'second',
                rounding: constant(RoundingMode::class.'::'.($cfg['rounding'] ?? 'HALF_UP')),
            );
        });

        $this->app->singleton(Meteric::class, fn ($app) => new Meteric($app->make(InvoiceManager::class)));

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
