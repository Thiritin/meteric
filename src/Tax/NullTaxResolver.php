<?php

declare(strict_types=1);

namespace Meteric\Tax;

use Brick\Money\Money;
use Meteric\Contracts\TaxResolver;

final class NullTaxResolver implements TaxResolver
{
    public function resolve(Money $net, TaxContext $context): TaxResult
    {
        return TaxResult::none($net->multipliedBy(0), 'No tax');
    }
}
