<?php

declare(strict_types=1);

namespace Meteric\Subscriptions;

use Brick\Money\Money;
use Meteric\Enums\LineKind;
use Meteric\Support\Period;

/**
 * What rebasePeriod() would do: the period the item would move to and the
 * charge the span would produce when prorated. `kind` is Prorated for an
 * extension, Credit for a shortening, null when the end does not move.
 * `amount` is the absolute figure; a credit is written negated.
 */
final readonly class RebasePreview
{
    public function __construct(
        public Period $period,
        public ?LineKind $kind,
        public Money $amount,
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'period' => $this->period->toArray(),
            'kind' => $this->kind?->value,
            'amount_minor' => $this->amount->getMinorAmount()->toInt(),
            'currency' => $this->amount->getCurrency()->getCurrencyCode(),
        ];
    }
}
