<?php

declare(strict_types=1);

namespace Meteric\Quoting;

use Brick\Money\Money;
use Carbon\CarbonImmutable;

/**
 * Read-only result of a quote: what's due now + what recurs. Serializable to
 * JSON for a checkout frontend. Produced by the same calculators as real billing
 * so it always matches the eventual invoice.
 *
 * @property list<QuoteLine> $lines
 */
final class Quote
{
    /** @param list<QuoteLine> $lines */
    public function __construct(
        public readonly string $currency,
        public readonly Money $dueNowSubtotal,
        public readonly Money $dueNowTax,
        public readonly Money $dueNowTotal,
        public readonly Money $recurringTotal,
        public readonly ?string $interval,
        public readonly ?int $intervalCount,
        public readonly ?CarbonImmutable $nextChargeAt,
        public readonly array $lines,
        public readonly bool $estimated = false,
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'currency' => $this->currency,
            'due_now' => [
                'subtotal_minor' => $this->dueNowSubtotal->getMinorAmount()->toInt(),
                'tax_minor' => $this->dueNowTax->getMinorAmount()->toInt(),
                'total_minor' => $this->dueNowTotal->getMinorAmount()->toInt(),
            ],
            'recurring' => [
                'interval' => $this->interval,
                'interval_count' => $this->intervalCount,
                'total_minor' => $this->recurringTotal->getMinorAmount()->toInt(),
                'next_charge_at' => $this->nextChargeAt?->toIso8601String(),
            ],
            'lines' => array_map(static fn (QuoteLine $l) => $l->toArray(), $this->lines),
            'estimated' => $this->estimated,
        ];
    }

    public function toJson(int $flags = 0): string
    {
        return json_encode($this->toArray(), $flags | JSON_THROW_ON_ERROR);
    }
}
