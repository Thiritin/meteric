<?php

declare(strict_types=1);

namespace Meteric\Pricing;

use Brick\Money\Money;
use Carbon\CarbonImmutable;
use Meteric\Enums\AnchorMode;
use Meteric\Enums\FirstPeriodPolicy;
use Meteric\Tax\TaxContext;

/** The knobs one checkout run prices under, bundled so they travel together. */
final readonly class CheckoutTerms
{
    public function __construct(
        public string $currency,
        public CarbonImmutable $at,
        public AnchorMode $anchorMode,
        public ?int $anchorDay,
        public FirstPeriodPolicy $firstPeriod,
        public int $trialDays,
        public TaxContext $taxContext,
    ) {}

    public function trialing(): bool
    {
        return $this->trialDays > 0;
    }

    public function zero(): Money
    {
        return Money::ofMinor(0, $this->currency);
    }
}
