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

    /**
     * Words between priced lines: a heading, a remark, a note about what
     * follows. It carries no amount and enters no total.
     */
    case Text = 'text';

    /**
     * Whether this kind is a product's base line (the parent), as opposed to a
     * configurable option, addon, setup, usage, discount, or credit sub-item.
     * A custom driver uses this to pick the parent charge within a line_group
     * and treat the rest as sub-items.
     */
    public function isBaseLine(): bool
    {
        return match ($this) {
            self::Recurring, self::Prorated, self::FullPeriod, self::OneOff => true,
            default => false,
        };
    }
}
