<?php

declare(strict_types=1);

namespace Billify;

use Billify\Contracts\Clock;
use Billify\Contracts\InvoiceDriver;
use Billify\Contracts\TaxResolver;
use Billify\Proration\Prorator;
use Billify\Support\SystemClock;
use Billify\Tax\EuVatResolver;
use Billify\Tax\FlatRateTaxResolver;
use Brick\Math\RoundingMode;
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
            $key = $cfg['driver'] ?? 'eu_vat';
            $class = $cfg['drivers'][$key] ?? EuVatResolver::class;

            if ($class === FlatRateTaxResolver::class) {
                return new FlatRateTaxResolver((float) ($cfg['flat_rate'] ?? 0.19));
            }

            return $app->make($class);
        });

        // Invoice driver — selected by config('billify.invoice.driver').
        $this->app->singleton(InvoiceDriver::class, function ($app) {
            $cfg = $app['config']['billify.invoice'];
            $key = $cfg['driver'] ?? 'database';
            $class = $cfg['drivers'][$key] ?? \Billify\Invoicing\Drivers\DatabaseInvoiceDriver::class;

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
        }

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
