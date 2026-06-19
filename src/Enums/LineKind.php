<?php

declare(strict_types=1);

namespace Meteric\Enums;

enum LineKind: string
{
    case Recurring = 'recurring';
    case Prorated = 'prorated';
    case FullPeriod = 'full_period';
    case Usage = 'usage';
    case Setup = 'setup';
    case OneOff = 'one_off';
    case Addon = 'addon';
    case Option = 'option';
    case Discount = 'discount';
    case Credit = 'credit';
}
