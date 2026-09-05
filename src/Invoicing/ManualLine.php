<?php

declare(strict_types=1);

namespace Meteric\Invoicing;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
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

    /**
     * The net price of one unit at this tax rate.
     *
     * A gross price is divided by one plus the rate. A zero rate divides by
     * one, so a reverse-charge or small-business document reads a gross entry
     * as the net it already is.
     */
    public function netUnitPrice(float $rate): Money
    {
        $price = $this->unitPrice ?? throw new \LogicException('A line carrying no money has no unit price.');

        if (! $this->priceIsGross || $rate <= 0.0) {
            return $price;
        }

        return $price->dividedBy(BigDecimal::of(1)->plus(BigDecimal::of((string) $rate)), RoundingMode::HALF_UP);
    }

    /**
     * Quantity times the net unit price, less the discount, **rounded once**.
     *
     * Public because a caller that shows a total before the line exists has to
     * show the total the line will have. Rounding the unit price first and
     * multiplying after makes a ten-of-something line disagree with the unit
     * price printed beside it by a cent, so the multiplication stays in decimal
     * to the end.
     */
    public function netTotal(float $rate): Money
    {
        $unit = $this->netUnitPrice($rate);

        $total = BigDecimal::of($unit->getAmount())->multipliedBy(BigDecimal::of((string) $this->quantity));

        if ($this->discountPercent > 0.0) {
            $keep = BigDecimal::of(100)->minus(BigDecimal::of((string) $this->discountPercent))->dividedBy(100, 10, RoundingMode::HALF_UP);
            $total = $total->multipliedBy($keep);
        }

        return Money::of($total, $unit->getCurrency(), roundingMode: RoundingMode::HALF_UP);
    }
}
