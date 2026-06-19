<?php

declare(strict_types=1);

namespace Meteric\Enums;

enum DiscountType: string
{
    case Percent = 'percent';
    case Fixed = 'fixed';
}
