<?php

declare(strict_types=1);

namespace Meteric\Invoicing;

use Brick\Money\Money;
use Meteric\Enums\LineKind;

/**
 * One line somebody typed, before the engine has priced it.
 *
 * `addLine()` takes a finished amount, which is all an automatic charge needs.
 * A person writing an invoice by hand states a quantity, a unit price and
 * sometimes a discount instead, and the amount is what falls out of those. That
 * arithmetic is the engine's, not the caller's: a panel that multiplied and
 * rounded on its own would be a second pricing implementation, and the two
 * would disagree the first time a rate changed.
 *
 * `unitPrice` is null on a line that carries no money. Such a line is a heading
 * or a remark between priced lines; it is stored so the document reads as it
 * was written, and it contributes nothing to any total.
 */
final readonly class ManualLine
{
    public function __construct(
        public string $title,
        public ?string $description = null,
        public float $quantity = 1.0,
        public ?string $unit = null,
        public ?Money $unitPrice = null,
        /** Per cent off this line, 0 to 100. */
        public float $discountPercent = 0.0,
        public LineKind $kind = LineKind::OneOff,
        /**
         * Whether `unitPrice` is what the customer pays including tax.
         *
         * The engine converts it to net once, using the rate its own resolver
         * gives for this invoice, so entering gross and entering net describe
         * the same document rather than two that differ by a rounding.
         */
        public bool $priceIsGross = false,
    ) {}

    /** A line that carries words and no money. */
    public static function text(string $title, ?string $description = null): self
    {
        return new self(title: $title, description: $description, quantity: 0.0, kind: LineKind::Text);
    }

    public function carriesMoney(): bool
    {
        return $this->unitPrice !== null && $this->kind !== LineKind::Text;
    }
}
