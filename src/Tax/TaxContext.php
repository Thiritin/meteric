<?php

declare(strict_types=1);

namespace Meteric\Tax;

use Brick\Money\Money;
use DateTimeInterface;

/**
 * Inputs a tax resolver needs: where the customer is and their status.
 *
 * `taxExempt` is the buyer's own exemption, granted to them by their tax
 * authority rather than derived from where they are. It is a separate input
 * from every other one here because no address, VAT id or product category
 * implies it: a diplomatic mission, a public body, a charity with a ruling all
 * buy from a registered merchant in a taxed country and are not charged. Every
 * resolver settles it before anything else, and `exemptReason` is the ground
 * the host recorded for it, which comes back as the result's label.
 *
 * It is not reverse charge and never labelled as one. Reverse charge moves the
 * liability to a VAT-registered buyer in another member state; an exemption
 * means nobody owes it, and a document that confuses the two states a legal
 * basis that does not apply.
 */
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
        public readonly bool $taxExempt = false,          // the buyer's own exemption, whatever the destination charges
        public readonly ?string $exemptReason = null,     // the ground for it, used as the result's label
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
    /** The zero this context resolves to where the buyer is exempt. */
    public function exemption(Money $zero): TaxResult
    {
        return TaxResult::none($zero, $this->exemptReason ?? 'Tax exempt');
    }

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
            taxExempt: $this->taxExempt,
            exemptReason: $this->exemptReason,
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
            taxExempt: (bool) ($profile['tax_exempt'] ?? false),
            exemptReason: $profile['tax_exempt_reason'] ?? null,
        );
    }
}
