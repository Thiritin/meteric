<?php

declare(strict_types=1);

namespace Meteric\Enums;

/**
 * When an account's pending charges become an invoice.
 *
 * `Immediate` is the engine's original behaviour and the default: whatever
 * raised a charge invoices the pool straight after, so a renewal, an upgrade
 * and a one-off each produce their own document on their own date.
 *
 * `Collective` defers that. Charges still accrue exactly as they did, but every
 * path that would invoice them leaves them pending, and one invoice per cycle
 * bills everything the account accrued. Nothing about the charges changes; only
 * the moment the document is written does.
 */
enum InvoiceSchedule: string
{
    case Immediate = 'immediate';
    case Collective = 'collective';

    public function defersInvoicing(): bool
    {
        return $this === self::Collective;
    }
}
