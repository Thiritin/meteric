<?php

declare(strict_types=1);

namespace Meteric\Tax;

use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Ibericode\Vat\Countries;
use Ibericode\Vat\Rates;
use Ibericode\Vat\Validator;
use InvalidArgumentException;
use Meteric\Contracts\TaxResolver;
use Throwable;

/**
 * Live EU VAT resolver backed by ibericode/vat.
 *
 *  - Rates come from the ibericode vat-rates service (auto-refreshed, date-aware)
 *    so they never go stale waiting on a package release.
 *  - Reverse charge requires a VAT id that actually validates against **VIES** —
 *    not merely a non-empty string.
 *  - If VIES can't confirm the id (down / malformed), we DO NOT zero-rate: VAT is
 *    charged. Failing safe avoids illegally exempting an unverified customer.
 *  - `TaxContext::$category` is the rate level, so a reduced-rate line resolves
 *    at the reduced rate. A level this country's rates do not carry falls back
 *    to standard, the same way `DatabaseTaxResolver` does, so a caller can set a
 *    category without knowing which destinations define one.
 */
final class IbericodeVatResolver implements TaxResolver
{
    public function __construct(
        private Rates $rates,
        private Validator $validator,
        private Countries $countries = new Countries,
        private string $merchantCountry = 'DE',
        private bool $verifyVatId = true,
    ) {}

    public function resolve(Money $net, TaxContext $context): TaxResult
    {
        $zero = $net->multipliedBy(0);
        $country = strtoupper($context->countryCode ?? $this->merchantCountry);
        $merchant = strtoupper($context->merchantCountry ?? $this->merchantCountry);

        // The buyer's own exemption, before anything about the destination:
        // an exempt buyer is not charged in a country the merchant is
        // registered in either, and this is not reverse charge.
        if ($context->taxExempt) {
            return $context->exemption($zero);
        }

        // Cross-border B2B inside the EU with a verified VAT id → reverse charge.
        if ($context->isBusiness
            && $context->vatId
            && $this->countries->isCountryCodeInEU($country)
            && $country !== $merchant
            && $this->vatIdIsValid($context->vatId)) {
            return TaxResult::none($zero, 'Reverse charge (Art. 196 VAT Directive)');
        }

        if (! $this->countries->isCountryCodeInEU($country)) {
            return TaxResult::none($zero, 'Non-EU, no VAT');
        }

        $rate = $this->rateFor($country, $context);
        if ($rate <= 0.0) {
            return TaxResult::none($zero);
        }

        $base = $context->taxInclusive
            ? $net->dividedBy((string) (1 + $rate), RoundingMode::HALF_UP)
            : $net;

        $tax = $base->multipliedBy((string) $rate, RoundingMode::HALF_UP);

        return new TaxResult($rate, $tax, sprintf('VAT %s%% (%s)', round($rate * 100, 2), $country));
    }

    /**
     * Rate as a fraction (ibericode returns percent). Date-aware when supplied,
     * and at the context's own rate level.
     *
     * An unknown level throws rather than answering zero, and a zero would be a
     * taxable supply billed tax-free, so it is caught and read as standard: a
     * category nobody defined here overcharges rather than undercharges.
     */
    private function rateFor(string $country, TaxContext $context): float
    {
        $percent = $this->percentFor($country, $context, $context->category);

        return $percent / 100;
    }

    private function percentFor(string $country, TaxContext $context, string $level): float
    {
        try {
            return $context->date !== null
                ? $this->rates->getRateForCountryOnDate($country, $context->date, $level)
                : $this->rates->getRateForCountry($country, $level);
        } catch (InvalidArgumentException) {
            return $level === Rates::RATE_STANDARD
                ? 0.0
                : $this->percentFor($country, $context, Rates::RATE_STANDARD);
        }
    }

    private function vatIdIsValid(string $vatId): bool
    {
        if (! $this->verifyVatId) {
            return true; // trust presence (e.g. validated upstream); not recommended
        }

        try {
            return $this->validator->validateVatNumber($vatId);
        } catch (Throwable) {
            return false; // VIES unreachable/ambiguous → fail safe, charge VAT
        }
    }
}
