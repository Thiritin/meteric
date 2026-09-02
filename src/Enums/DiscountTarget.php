<?php

declare(strict_types=1);

namespace Meteric\Enums;

/**
 * What a discount reduces. `Line` is the item's billed period: its own charge
 * plus its addons and options. `Setup` is the one-time setup fee charged when
 * the item is first materialized.
 */
enum DiscountTarget: string
{
    case Line = 'line';
    case Setup = 'setup';
}
