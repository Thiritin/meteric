<?php

declare(strict_types=1);

namespace Meteric\Catalog;

use Meteric\Contracts\CatalogDefaults;

/** The catalog defaults a deployment sets in config; unset in both keys by default. */
final class ConfigCatalogDefaults implements CatalogDefaults
{
    public function cancelNoticeDays(): ?int
    {
        return $this->read('default_cancel_notice_days');
    }

    public function minimumTermPeriods(): ?int
    {
        return $this->read('default_minimum_term_periods');
    }

    private function read(string $key): ?int
    {
        $value = config('meteric.catalog.'.$key);

        return $value === null || $value === '' ? null : max(0, (int) $value);
    }
}
