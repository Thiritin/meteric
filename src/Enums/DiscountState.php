<?php

declare(strict_types=1);

namespace Meteric\Enums;

enum DiscountState: string
{
    case Active = 'active';
    case Exhausted = 'exhausted';
    case Canceled = 'canceled';
}
