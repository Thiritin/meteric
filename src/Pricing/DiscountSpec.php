<?php

declare(strict_types=1);

namespace Meteric\Pricing;

use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Meteric\Enums\DiscountKind;
use Meteric\Enums\DiscountTarget;

/**
 * A discount before it is a row: what to take off, what it applies to, and for
 * how many billed periods. It is what a checkout freezes on an order line and
 * what `Meteric::applyDiscount()` takes, so the figure quoted and the figure
 * billed come from one piece of arithmetic.
 *
 * `terms` counts billed periods, not calendar months. A signup that bills a
 * stub and a first full month has spent two of them.
 */
final readonly class DiscountSpec
{
    /** @param array<string,mixed> $metadata */
    public function __construct(
        public DiscountKind $kind,
        public string $label,
        public ?string $percent = null,
        public ?int $amountMinor = null,
        public ?string $currency = null,
        public DiscountTarget $target = DiscountTarget::Line,
        public ?int $terms = null,
        public array $metadata = [],
    ) {
        if ($kind === DiscountKind::Percent && ($percent === null || (float) $percent <= 0 || (float) $percent > 100)) {
            throw new \InvalidArgumentException('A percent discount needs a percent above 0 and at most 100.');
        }
        if ($kind === DiscountKind::Fixed && ($amountMinor === null || $amountMinor <= 0 || $currency === null)) {
            throw new \InvalidArgumentException('A fixed discount needs a positive amount and a currency.');
        }
        if ($terms !== null && $terms < 1) {
            throw new \InvalidArgumentException('A discount runs for at least one term, or for null terms (no limit).');
        }
    }

    /** @param array<string,mixed> $metadata */
    public static function percent(string $percent, string $label, DiscountTarget $target = DiscountTarget::Line, ?int $terms = null, array $metadata = []): self
    {
        return new self(DiscountKind::Percent, $label, percent: $percent, target: $target, terms: $terms, metadata: $metadata);
    }

    /** @param array<string,mixed> $metadata */
    public static function fixed(int $amountMinor, string $currency, string $label, DiscountTarget $target = DiscountTarget::Line, ?int $terms = null, array $metadata = []): self
    {
        return new self(DiscountKind::Fixed, $label, amountMinor: $amountMinor, currency: $currency, target: $target, terms: $terms, metadata: $metadata);
    }

    /**
     * What this takes off `$base`, as a positive magnitude. Never more than the
     * base and never negative, so a discount can zero a line but not invert it.
     * A fixed discount in another currency takes off nothing.
     */
    public function reduce(Money $base): Money
    {
        $zero = $base->multipliedBy(0);

        if (! $base->isPositive()) {
            return $zero;
        }

        $off = match ($this->kind) {
            DiscountKind::Percent => $base->multipliedBy((string) ((float) $this->percent / 100), RoundingMode::HALF_UP),
            DiscountKind::Fixed => $this->currency === $base->getCurrency()->getCurrencyCode()
                ? Money::ofMinor((int) $this->amountMinor, $base->getCurrency())
                : $zero,
        };

        return $off->isGreaterThan($base) ? $base : $off;
    }

    /** The frozen shape written onto an order line and read back by the converter. */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind->value,
            'label' => $this->label,
            'percent' => $this->percent,
            'amount_minor' => $this->amountMinor,
            'currency' => $this->currency,
            'target' => $this->target->value,
            'terms' => $this->terms,
            'metadata' => $this->metadata,
        ];
    }

    /** @param array<string,mixed> $frozen */
    public static function fromArray(array $frozen): self
    {
        return new self(
            kind: DiscountKind::from($frozen['kind']),
            label: (string) $frozen['label'],
            percent: $frozen['percent'] ?? null,
            amountMinor: isset($frozen['amount_minor']) ? (int) $frozen['amount_minor'] : null,
            currency: $frozen['currency'] ?? null,
            target: DiscountTarget::from($frozen['target'] ?? DiscountTarget::Line->value),
            terms: isset($frozen['terms']) ? (int) $frozen['terms'] : null,
            metadata: $frozen['metadata'] ?? [],
        );
    }
}
