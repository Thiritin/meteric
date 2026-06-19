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
use Meteric\Console\VatSyncCommand;
use Meteric\Contracts\Clock;
use Meteric\Contracts\InvoiceDriver;
use Meteric\Contracts\TaxResolver;
use Meteric\Invoicing\Drivers\DatabaseInvoiceDriver;
use Meteric\Proration\Prorator;
use Meteric\Quoting\QuoteBuilder;
use Meteric\Subscriptions\CommitmentManager;
use Meteric\Subscriptions\ItemManager;
use Meteric\Subscriptions\SubscriptionBuilder;
use Meteric\Subscriptions\SubscriptionManager;
use Meteric\Support\SystemClock;
use Meteric\Tax\DatabaseTaxResolver;
use Meteric\Tax\EuVatResolver;
use Meteric\Tax\FlatRateTaxResolver;
use Meteric\Tax\IbericodeVatResolver;
use Meteric\Usage\UsageRollup;

final class MetericServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/meteric.php', 'meteric');

        $this->app->singleton(Clock::class, SystemClock::class);

        // Tax resolver — selected by config('meteric.tax.driver').
        $this->app->singleton(TaxResolver::class, function ($app) {
            $cfg = $app['config']['meteric.tax'];
            $key = $cfg['driver'] ?? 'ibericode';
            $class = $cfg['drivers'][$key] ?? EuVatResolver::class;

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
            $class = $cfg['drivers'][$key] ?? DatabaseInvoiceDriver::class;

            return $app->make($class);
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

        $this->app->singleton(CommitmentManager::class, fn ($app) => new CommitmentManager(
            clock: $app->make(Clock::class),
        ));

        // Fresh builder per subscribe (stateful).
        $this->app->bind(SubscriptionBuilder::class, fn ($app) => new SubscriptionBuilder(
            clock: $app->make(Clock::class),
            planner: $app->make(PeriodPlanner::class),
            accruer: $app->make(ChargeAccruer::class),
            defaultCurrency: $app['config']['meteric.currency'] ?? 'EUR',
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

            $this->commands([VatSyncCommand::class]);
        }

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
