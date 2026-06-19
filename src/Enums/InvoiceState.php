<?php

declare(strict_types=1);

namespace Meteric\Enums;

enum InvoiceState: string
{
    case Draft = 'draft';
    case Open = 'open';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Void = 'void';
    case Uncollectible = 'uncollectible';

    public function isIssued(): bool
    {
        return $this !== self::Draft;
    }

    public function isImmutable(): bool
    {
        return $this !== self::Draft;
    }
}
