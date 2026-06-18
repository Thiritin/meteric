<?php

declare(strict_types=1);

namespace Billify;

use Billify\Anchoring\PeriodPlanner;
use Billify\Charges\ChargeAccruer;
use Billify\Console\VatSyncCommand;
use Billify\Contracts\Clock;
use Billify\Contracts\InvoiceDriver;
use Billify\Contracts\TaxResolver;
use Billify\Invoicing\Drivers\DatabaseInvoiceDriver;
use Billify\Proration\Prorator;
use Billify\Quoting\QuoteBuilder;
use Billify\Subscriptions\ItemManager;
use Billify\Subscriptions\SubscriptionBuilder;
use Billify\Subscriptions\SubscriptionManager;
use Billify\Support\SystemClock;
use Billify\Tax\DatabaseTaxResolver;
use Billify\Tax\EuVatResolver;
use Billify\Tax\FlatRateTaxResolver;
use Billify\Tax\IbericodeVatResolver;
use Billify\Usage\UsageRollup;
use Brick\Math\RoundingMode;
use Ibericode\Vat\Countries;
use Ibericode\Vat\Rates;
use Ibericode\Vat\Validator;
use Illuminate\Support\ServiceProvider;

final class BillifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/billify.php', 'billify');

        $this->app->singleton(Clock::class, SystemClock::class);

        // Tax resolver — selected by config('billify.tax.driver').
        $this->app->singleton(TaxResolver::class, function ($app) {
            $cfg = $app['config']['billify.tax'];
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
            $ib = $app['config']['billify.tax.ibericode'] ?? [];
            $path = $ib['storage_path'] ?? storage_path('framework/cache/billify-vat-rates.json');
            @mkdir(dirname((string) $path), 0775, true);

            return new Rates((string) $path, (int) ($ib['refresh_interval'] ?? 43200));
        });

        // Invoice driver — selected by config('billify.invoice.driver').
        $this->app->singleton(InvoiceDriver::class, function ($app) {
            $cfg = $app['config']['billify.invoice'];
            $key = $cfg['driver'] ?? 'database';
            $class = $cfg['drivers'][$key] ?? DatabaseInvoiceDriver::class;

            return $app->make($class);
        });

        $this->app->singleton(Prorator::class, function ($app) {
            $cfg = $app['config']['billify'];

            return new Prorator(
                unit: $cfg['proration']['unit'] ?? 'second',
                rounding: constant(RoundingMode::class.'::'.($cfg['rounding'] ?? 'HALF_UP')),
            );
        });

        $this->app->singleton(Billify::class, fn ($app) => new Billify($app->make(InvoiceDriver::class)));

        $this->app->singleton(PeriodPlanner::class);
        $this->app->singleton(ChargeAccruer::class, fn ($app) => new ChargeAccruer($app->make(Prorator::class)));
        $this->app->singleton(UsageRollup::class);

        // Fresh builder per quote (stateful).
        $this->app->bind(QuoteBuilder::class, fn ($app) => new QuoteBuilder(
            clock: $app->make(Clock::class),
            prorator: $app->make(Prorator::class),
            tax: $app->make(TaxResolver::class),
            planner: $app->make(PeriodPlanner::class),
            currency: $app['config']['billify.currency'] ?? 'EUR',
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
            defaultCurrency: $app['config']['billify.currency'] ?? 'EUR',
        ));
    }

    /** @param array<string,mixed> $cfg billify.tax config */
    private function makeIbericodeResolver(array $cfg): IbericodeVatResolver
    {
        $ib = $cfg['ibericode'] ?? [];
        $path = $ib['storage_path'] ?? storage_path('framework/cache/billify-vat-rates.json');
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
                __DIR__.'/../config/billify.php' => config_path('billify.php'),
            ], 'billify-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'billify-migrations');

            $this->commands([VatSyncCommand::class]);
        }

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
