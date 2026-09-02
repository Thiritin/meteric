<?php

declare(strict_types=1);

namespace Meteric\Invoicing;

use Brick\Money\Money;

/**
 * What a credit note should say. `lines` is optional: when given, each entry
 * reverses part of one invoice line at that line's own tax and the driver
 * stores them as CreditNoteLine rows; without it the note is a single net
 * amount taxed at the invoice's blended rate.
 *
 * @property list<array{invoice_line_id:?string,title:?string,net_minor:int,tax_minor:int,tax_rate:float}> $lines
 */
final class CreditNoteDraft
{
    /** @param list<array{invoice_line_id:?string,title:?string,net_minor:int,tax_minor:int,tax_rate:float}> $lines */
    public function __construct(
        public readonly Money $amount,
        public readonly ?string $reason = null,
        public readonly array $meta = [],
        public readonly array $lines = [],
    ) {}
}
