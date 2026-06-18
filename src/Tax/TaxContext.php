<?php

declare(strict_types=1);

namespace Billify\Tax;

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
