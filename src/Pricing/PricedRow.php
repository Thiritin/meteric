<?php

declare(strict_types=1);

namespace Meteric\Pricing;

use Brick\Money\Money;
use Carbon\CarbonImmutable;
use Meteric\Quoting\QuoteLine;

/**
 * One priced cart row: the frozen `contents` entry, its display line, and the
 * aggregation deltas the cart totals fold up.
 */
final readonly class PricedRow
{
    /** @param array<string,mixed> $content */
    public function __construct(
        public array $content,
        public QuoteLine $line,
        public Money $dueNow,
        public Money $recurring,
        public ?string $interval,
        public ?int $intervalCount,
        public ?CarbonImmutable $nextChargeAt,
        public bool $estimated,
    ) {}
}
