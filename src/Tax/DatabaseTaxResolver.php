<?php

declare(strict_types=1);

namespace Meteric\Tax;

use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Carbon\CarbonImmutable;
use Ibericode\Vat\Countries;
use Ibericode\Vat\Validator;
use Meteric\Contracts\TaxResolver;
use Meteric\Models\TaxRate;
use Meteric\Models\TaxRegistration;
use Meteric\Support\Models;
use Throwable;

/**
 * Multi-jurisdiction, configurable tax resolver — the default.
 *
 * Tax is charged only where the merchant is **registered** (a direct
 * `meteric_tax_registrations` row, or an `eu_oss` row covering the EU). The rate
 * comes from the editable `meteric_tax_rates` table (date-versioned, per product
 * category). EU rows are kept fresh by `meteric:vat-sync`; non-EU jurisdictions
 * (Switzerland, UK, …) are added manually. EU cross-border B2B reverse charge is
 * confirmed via VIES when a validator is available.
 */
final class DatabaseTaxResolver implements TaxResolver
{
    public function __construct(
        private Countries $countries = new Countries,
        private ?Validator $validator = null,
        private string $merchantCountry = 'DE',
    ) {}

    public function resolve(Money $net, TaxContext $context): TaxResult
    {
        $zero = $net->multipliedBy(0);
        $date = $context->date
            ? CarbonImmutable::instance(CarbonImmutable::parse($context->date->format('c')))
            : CarbonImmutable::now();
        $country = strtoupper($context->countryCode ?? $this->merchantCountry);
        $merchant = strtoupper($context->merchantCountry ?? $this->merchantCountry);

        // EU cross-border B2B with a verified VAT id → reverse charge.
        if ($context->isBusiness
            && $context->vatId
            && $this->countries->isCountryCodeInEU($country)
            && $country !== $merchant
            && $this->vatIdIsValid($context->vatId)) {
            return TaxResult::none($zero, 'Reverse charge (Art. 196 VAT Directive)');
        }

        if (! $this->registeredFor($country, $date)) {
            return TaxResult::none($zero, 'Not registered, out of scope');
        }

        $rate = $this->rateFor($country, $context->category, $date);
        if ($rate === null || $rate->rateFraction() <= 0.0) {
            return TaxResult::none($zero);
        }

        $fraction = $rate->rateFraction();
        $base = $context->taxInclusive
            ? $net->dividedBy(1 + $fraction, RoundingMode::HALF_UP)
            : $net;
        $tax = $base->multipliedBy($fraction, RoundingMode::HALF_UP);

        $label = $rate->label ?? sprintf('VAT %s%% (%s)', round($fraction * 100, 2), $country);

        return new TaxResult($fraction, $tax, $label);
    }

    private function registeredFor(string $country, CarbonImmutable $date): bool
    {
        return Models::query(TaxRegistration::class)->activeOn($date)
            ->where(function ($q) use ($country) {
                $q->where('country', $country);
                if ($this->countries->isCountryCodeInEU($country)) {
                    $q->orWhere('scheme', 'eu_oss');  // OSS covers all EU destinations
                }
            })
            ->exists();
    }

    private function rateFor(string $country, string $category, CarbonImmutable $date): ?TaxRate
    {
        $find = fn (string $cat) => Models::query(TaxRate::class)->activeOn($date)
            ->where('country', $country)
            ->where('category', $cat)
            ->orderByDesc('effective_from')
            ->first();

        return $find($category) ?? ($category !== 'standard' ? $find('standard') : null);
    }

    private function vatIdIsValid(string $vatId): bool
    {
        if ($this->validator === null) {
            return false; // can't confirm → don't reverse-charge (fail safe)
        }

        try {
            return $this->validator->validateVatNumber($vatId);
        } catch (Throwable) {
            return false;
        }
    }
}
