<?php

declare(strict_types=1);

namespace Meteric\Invoicing;

/** Driver's confirmation that an invoice exists in the target system. */
final class IssuedInvoice
{
    public function __construct(
        public readonly string $invoiceId,      // Meteric invoice id
        public readonly ?string $number = null,
        public readonly ?string $externalId = null,
        public readonly ?string $externalUrl = null,
    ) {}
}
