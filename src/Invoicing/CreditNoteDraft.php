<?php

declare(strict_types=1);

namespace Meteric\Invoicing;

use Brick\Money\Money;

final class CreditNoteDraft
{
    public function __construct(
        public readonly Money $amount,
        public readonly ?string $reason = null,
        public readonly array $meta = [],
    ) {}
}
