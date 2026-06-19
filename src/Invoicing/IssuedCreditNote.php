<?php

declare(strict_types=1);

namespace Meteric\Invoicing;

final class IssuedCreditNote
{
    public function __construct(
        public readonly string $creditNoteId,
        public readonly ?string $number = null,
        public readonly ?string $externalId = null,
    ) {}
}
