<?php

declare(strict_types=1);

namespace Billify\Tax;

use Billify\Contracts\TaxResolver;
use Brick\Money\Money;
use Brick\Math\RoundingMode;

final class FlatRateTaxResolver implements TaxResolver
{
    public function __construct(private float $rate = 0.19) {}

    public function resolve(Money $net, TaxContext $context): TaxResult
    {
        $base = $context->taxInclusive
            ? $net->dividedBy(1 + $this->rate, RoundingMode::HALF_UP)
            : $net;

        $tax = $base->multipliedBy($this->rate, RoundingMode::HALF_UP);

        return new TaxResult($this->rate, $tax, sprintf('Tax %s%%', $this->rate * 100));
    }
}
