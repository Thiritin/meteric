<?php

declare(strict_types=1);

namespace Meteric\Tax;

use DateTimeInterface;

/** Inputs a tax resolver needs: where the customer is and their status. */
final class TaxContext
{
    public function __construct(
        public readonly ?string $countryCode = null,   // ISO-3166 alpha-2
        public readonly bool $isBusiness = false,
        public readonly ?string $vatId = null,
        public readonly bool $taxInclusive = false,
        public readonly ?string $merchantCountry = null,
        public readonly ?DateTimeInterface $date = null,  // supply date → historical rate
        public readonly string $category = 'standard',    // product tax class (reduced, lodging, …)
    ) {}

    /**
     * The same context, priced under a different product tax class.
     *
     * The category selects which of the destination's rate rows applies; it
     * cannot change the treatment, because a resolver settles reverse charge
     * and out-of-scope before it looks a rate up at all. So this is safe to
     * hand to a caller that lets a person choose per line: the worst a wrong
     * category can do is bill the standard rate, which is what an unknown one
     * falls back to.
     */
    public function withCategory(string $category): self
    {
        return new self(
            countryCode: $this->countryCode,
            isBusiness: $this->isBusiness,
            vatId: $this->vatId,
            taxInclusive: $this->taxInclusive,
            merchantCountry: $this->merchantCountry,
            date: $this->date,
            category: $category,
        );
    }

    /** @param array<string,mixed> $profile A BillingAccount tax_profile. */
    public static function fromProfile(array $profile, bool $taxInclusive = false): self
    {
        return new self(
            countryCode: $profile['country'] ?? null,
            isBusiness: (bool) ($profile['b2b'] ?? false),
            vatId: $profile['vat_id'] ?? null,
            taxInclusive: $taxInclusive,
            merchantCountry: $profile['merchant_country'] ?? null,
        );
    }
}
