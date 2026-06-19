<?php

declare(strict_types=1);

namespace Meteric\Enums;

enum ChargeState: string
{
    case Pending = 'pending';     // accrued, not yet invoiced (source of truth)
    case Invoiced = 'invoiced';   // attached to an issued invoice
    case Settled = 'settled';     // paid
    case Void = 'void';           // corrected / cancelled
}
