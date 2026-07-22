<?php

declare(strict_types=1);

namespace Meteric\Tax;

use Ibericode\Vat\Countries;
use Ibericode\Vat\Rates;
use Ibericode\Vat\Validator;
use Illuminate\Support\Manager;
use Meteric\Contracts\TaxResolver;

/**
 * Resolves the configured tax driver (config meteric.tax.driver). Hosts add
 * their own with extend('name', fn) or by pointing a key in
 * config('meteric.tax.drivers') at a container-resolvable class.
 */
final class TaxResolverManager extends Manager
{
    /** Built-in resolvers, keyed by driver name. */
    private const DRIVERS = [
        'database' => DatabaseTaxResolver::class,
        'ibericode' => IbericodeVatResolver::class,
        'eu_vat' => EuVatResolver::class,
        'flat' => FlatRateTaxResolver::class,
        'null' => NullTaxResolver::class,
    ];

    public function getDefaultDriver(): string
    {
        return (string) $this->config->get('meteric.tax.driver', 'database');
    }

    /**
     * A driver key repointed at a host-app class in the published config
     * resolves from the container; extend() closures and the built-in
     * create methods take it from there.
     *
     * @param  string  $driver
     */
    protected function createDriver($driver): TaxResolver
    {
        $mapped = $this->config->get("meteric.tax.drivers.{$driver}");

        if (! isset($this->customCreators[$driver]) && $mapped !== null && $mapped !== (self::DRIVERS[$driver] ?? null)) {
            return $this->container->make($mapped);
        }

        return parent::createDriver($driver);
    }

    protected function createDatabaseDriver(): DatabaseTaxResolver
    {
        $cfg = (array) $this->config->get('meteric.tax', []);

        return new DatabaseTaxResolver(
            countries: new Countries,
            validator: ($cfg['ibericode']['verify_vat_id'] ?? true) ? new Validator : null,
            merchantCountry: $cfg['merchant_country'] ?? 'DE',
        );
    }

    protected function createIbericodeDriver(): IbericodeVatResolver
    {
        $cfg = (array) $this->config->get('meteric.tax', []);
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

    protected function createEuVatDriver(): EuVatResolver
    {
        return new EuVatResolver(
            merchantCountry: $this->config->get('meteric.tax.merchant_country', 'DE'),
        );
    }

    protected function createFlatDriver(): FlatRateTaxResolver
    {
        return new FlatRateTaxResolver((float) $this->config->get('meteric.tax.flat_rate', 0.19));
    }

    protected function createNullDriver(): NullTaxResolver
    {
        return new NullTaxResolver;
    }
}
