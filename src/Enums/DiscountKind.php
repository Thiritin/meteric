<?php

declare(strict_types=1);

namespace Meteric\Enums;

/** How a discount's reduction is worked out from the amount it applies to. */
enum DiscountKind: string
{
    case Percent = 'percent';
    case Fixed = 'fixed';
}
