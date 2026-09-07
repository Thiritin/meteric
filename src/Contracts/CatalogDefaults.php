<?php

declare(strict_types=1);

namespace Meteric\Contracts;

/**
 * The figures a catalog falls back to where neither a price nor its product
 * states one.
 *
 * Bound to `ConfigCatalogDefaults` by the service provider, which reads static
 * config. A host whose defaults are editable rather than deployed binds its own
 * implementation; it is resolved on every read, so a value that moves with the
 * request (a tenant, a brand, a currency) answers for the request it is in.
 *
 * Null is "no default", which is not zero: zero is a figure a price may state
 * and means no notice and no term at all.
 */
interface CatalogDefaults
{
    /** Days of notice a cancellation needs, or null where the deployment states none. */
    public function cancelNoticeDays(): ?int;

    /** Periods a new sale is committed for, or null where the deployment states none. */
    public function minimumTermPeriods(): ?int;
}
