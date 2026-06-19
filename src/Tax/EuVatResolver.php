<?php

declare(strict_types=1);

namespace Meteric\Tax;

use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Meteric\Contracts\TaxResolver;

/**
 * EU VAT (default driver).
 *
 * Rules implemented:
 *  - B2B with a VAT id in a different EU country  → reverse charge, 0% (exempt).
 *  - B2C (or same-country B2B)                    → destination-country rate.
 *  - Non-EU destination                           → 0% (out of scope of EU VAT).
 *  - Tax-inclusive prices are back-calculated.
 *
 * Rate table is data the host keeps current; pass it in via config. Defaults
 * cover the common standard rates as a starting point.
 */
final class EuVatResolver implements TaxResolver
{
    /** @param array<string,float> $rates ISO country => standard rate */
    public function __construct(
        private array $rates = self::DEFAULT_RATES,
        private string $merchantCountry = 'DE',
    ) {}

    public const DEFAULT_RATES = [
        'DE' => 0.19, 'AT' => 0.20, 'FR' => 0.20, 'NL' => 0.21, 'BE' => 0.21,
        'IT' => 0.22, 'ES' => 0.21, 'PL' => 0.23, 'IE' => 0.23, 'FI' => 0.24,
        'DK' => 0.25, 'SE' => 0.25, 'LU' => 0.17,
    ];

    public function resolve(Money $net, TaxContext $context): TaxResult
    {
        $zero = $net->multipliedBy(0);
        $country = strtoupper($context->countryCode ?? $this->merchantCountry);
        $merchant = strtoupper($context->merchantCountry ?? $this->merchantCountry);

        // Reverse charge: cross-border B2B inside the EU with a valid VAT id.
        if ($context->isBusiness
            && $context->vatId
            && $this->isEu($country)
            && $country !== $merchant) {
            return TaxResult::none($zero, 'Reverse charge (Art. 196 VAT Directive)');
        }

        // Non-EU destination: outside EU VAT scope.
        if (! $this->isEu($country)) {
            return TaxResult::none($zero, 'Non-EU — no VAT');
        }

        $rate = $this->rates[$country] ?? ($this->rates[$merchant] ?? 0.0);
        if ($rate === 0.0) {
            return TaxResult::none($zero);
        }

        $base = $context->taxInclusive
            ? $net->dividedBy(1 + $rate, RoundingMode::HALF_UP)
            : $net;

        $tax = $base->multipliedBy($rate, RoundingMode::HALF_UP);

        return new TaxResult($rate, $tax, sprintf('VAT %s%% (%s)', round($rate * 100), $country));
    }

    private function isEu(string $country): bool
    {
        return array_key_exists($country, self::DEFAULT_RATES) || array_key_exists($country, $this->rates);
    }
}
