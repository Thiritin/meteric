<?php

declare(strict_types=1);

namespace Meteric\Contracts;

use Brick\Money\Money;
use Meteric\Tax\TaxContext;
use Meteric\Tax\TaxResult;

/**
 * Resolves tax for a single net amount in a given context. Swappable driver;
 * Meteric ships EuVatResolver (default), FlatRateTaxResolver, NullTaxResolver.
 */
interface TaxResolver
{
    public function resolve(Money $net, TaxContext $context): TaxResult;
}
