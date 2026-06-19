<?php

declare(strict_types=1);

namespace Meteric\Tax;

use Brick\Money\Money;

final class TaxResult
{
    public function __construct(
        public readonly float $rate,        // e.g. 0.19
        public readonly Money $amount,      // tax money
        public readonly string $label,      // 'USt 19%' | 'Reverse charge'
        public readonly bool $exempt = false,
    ) {}

    public static function none(Money $zero, string $label = 'Tax-free'): self
    {
        return new self(0.0, $zero, $label, true);
    }
}
