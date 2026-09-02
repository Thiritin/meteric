<?php

declare(strict_types=1);

namespace Meteric\Pricing;

use Meteric\Quoting\Quote;

/**
 * The priced, frozen result of a checkout cart: the `contents` array (written
 * verbatim to the Order) plus the order-level totals and a quote snapshot for
 * display. The per-row minor amounts inside `contents` are the source of truth
 * for immutability.
 */
final class FrozenCart
{
    /**
     * @param  list<array<string,mixed>>  $contents
     * @param  array<string,mixed>  $quoteSnapshot
     */
    public function __construct(
        public readonly array $contents,
        public readonly int $subtotalMinor,
        public readonly int $taxMinor,
        public readonly int $totalMinor,
        public readonly int $recurringTotalMinor,
        public readonly Quote $quote,
        public readonly array $quoteSnapshot,
    ) {}
}
