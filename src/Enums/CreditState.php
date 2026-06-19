<?php

declare(strict_types=1);

namespace Meteric\Enums;

enum CreditState: string
{
    case Draft = 'draft';
    case Issued = 'issued';
    case Applied = 'applied';
    case Void = 'void';
}
