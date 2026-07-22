<?php

declare(strict_types=1);

namespace Meteric\Invoicing;

use Illuminate\Support\Manager;
use Meteric\Contracts\InvoiceDriver;
use Meteric\Invoicing\Drivers\DatabaseInvoiceDriver;
use Meteric\Invoicing\Drivers\LexofficeInvoiceDriver;

/**
 * Resolves the configured invoice driver (config meteric.invoice.driver).
 * Hosts add their own with extend('name', fn) or by pointing a key in
 * config('meteric.invoice.drivers') at a container-resolvable class.
 */
final class InvoiceDriverManager extends Manager
{
    /** Built-in drivers, keyed by driver name. */
    private const DRIVERS = [
        'database' => DatabaseInvoiceDriver::class,
        'lexoffice' => LexofficeInvoiceDriver::class,
    ];

    public function getDefaultDriver(): string
    {
        return (string) $this->config->get('meteric.invoice.driver', 'database');
    }

    /**
     * A driver key repointed at a host-app class in the published config
     * resolves from the container; extend() closures and the built-in
     * create methods take it from there.
     *
     * @param  string  $driver
     */
    protected function createDriver($driver): InvoiceDriver
    {
        $mapped = $this->config->get("meteric.invoice.drivers.{$driver}");

        if (! isset($this->customCreators[$driver]) && $mapped !== null && $mapped !== (self::DRIVERS[$driver] ?? null)) {
            return $this->container->make($mapped);
        }

        return parent::createDriver($driver);
    }

    protected function createDatabaseDriver(): DatabaseInvoiceDriver
    {
        return $this->container->make(DatabaseInvoiceDriver::class);
    }

    protected function createLexofficeDriver(): LexofficeInvoiceDriver
    {
        $cfg = (array) $this->config->get('meteric.invoice.lexoffice', []);

        return new LexofficeInvoiceDriver(
            local: $this->container->make(DatabaseInvoiceDriver::class),
            apiToken: (string) ($cfg['api_token'] ?? ''),
            baseUrl: $cfg['base_url'] ?? 'https://api.lexoffice.io',
            taxType: $cfg['tax_type'] ?? 'net',
            defaultCountry: $cfg['country'] ?? 'DE',
        );
    }
}
