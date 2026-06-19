<?php

declare(strict_types=1);

namespace Meteric\Enums;

enum PricePurpose: string
{
    case Recurring = 'recurring';
    case Setup = 'setup';
    case Register = 'register';
    case Renew = 'renew';
    case Transfer = 'transfer';
    case Addon = 'addon';
    case Option = 'option';
}
