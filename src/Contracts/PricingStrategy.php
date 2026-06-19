<?php

declare(strict_types=1);

namespace Meteric\Contracts;

use Brick\Money\Money;
use Meteric\Models\Price;
use Meteric\Pricing\PricingContext;

/**
 * Strategy for turning a quantity + price into a Money amount.
 * One implementation per PricingModel enum case.
 */
interface PricingStrategy
{
    public function price(float $quantity, Price $price, PricingContext $context): Money;
}
