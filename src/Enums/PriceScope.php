<?php

declare(strict_types=1);

namespace Meteric\Enums;

/**
 * Whether a price belongs to the catalog or to one subscription item.
 *
 * **This is the distinction that makes a per-item override safe.** From the
 * outside an override looks like "just another price row", and the temptation
 * is to write one into the catalog and keep it out of listings by convention.
 * Convention is what fails: the next listing anyone adds does not know about it.
 *
 * A price with `scope = override` is not a catalog object. It is never offered
 * for sale, never replaced by the catalog's price-replacement flow, never
 * grandfathered, and never listed beside the prices a product sells at. It
 * exists so that one subscription item can bill an amount its product does not
 * publish, and `Price::query()->catalog()` is how every listing excludes it.
 */
enum PriceScope: string
{
    case Catalog = 'catalog';
    case Override = 'override';

    public function isCatalog(): bool
    {
        return $this === self::Catalog;
    }
}
