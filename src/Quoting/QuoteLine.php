<?php

declare(strict_types=1);

namespace Meteric\Quoting;

use Brick\Money\Money;
use Meteric\Enums\LineKind;
use Meteric\Support\Period;

/** One line of a quote. Serializes to a stable shape for checkout frontends. */
final class QuoteLine
{
    public function __construct(
        public readonly string $label,
        public readonly LineKind $kind,
        public readonly float $quantity,
        public readonly Money $amount,
        public readonly Money $tax,
        public readonly ?Period $covers = null,
        public readonly ?string $unitRate = null,
        public readonly bool $estimated = false,
    ) {}

    public function gross(): Money
    {
        return $this->amount->plus($this->tax);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'label' => $this->label,
            'kind' => $this->kind->value,
            'quantity' => $this->quantity,
            'covers' => $this->covers?->toArray(),
            'unit_rate' => $this->unitRate,
            'amount_minor' => $this->amount->getMinorAmount()->toInt(),
            'tax_minor' => $this->tax->getMinorAmount()->toInt(),
            'estimated' => $this->estimated,
        ];
    }
}
