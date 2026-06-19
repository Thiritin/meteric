<?php

declare(strict_types=1);

namespace Meteric\Enums;

enum OptionType: string
{
    case Quantity = 'quantity';   // per-unit / tiered (slots, IPs)
    case Choice = 'choice';       // dropdown / radio (location, OS)
    case Toggle = 'toggle';       // yes/no flag
}
