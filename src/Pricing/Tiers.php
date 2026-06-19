<?php

declare(strict_types=1);

namespace Meteric\Pricing;

use Brick\Money\Money;

/**
 * Quantity tier pricing. A tier is `{ "up_to": int|null, "unit_minor": int }`,
 * ordered low to high, where `up_to` is the inclusive upper bound of that tier
 * and `null` means unbounded (the last tier). Both shapes answer "the more you
 * buy, the cheaper" when the rates decrease.
 *
 *   Volume:    the whole quantity is priced at the tier it lands in.
 *   Graduated: each tier's slice is priced at its own rate, then summed.
 */
final class Tiers
{
    /**
     * @param  list<array{up_to:int|null,unit_minor:int}>  $tiers
     */
    public static function volume(array $tiers, float $quantity, string $currency): Money
    {
        $rate = self::rateFor($tiers, $quantity);

        return Money::ofMinor((int) round($rate * $quantity), $currency);
    }

    /**
     * @param  list<array{up_to:int|null,unit_minor:int}>  $tiers
     */
    public static function graduated(array $tiers, float $quantity, string $currency): Money
    {
        $total = 0.0;
        $prev = 0.0;

        foreach ($tiers as $tier) {
            $bound = $tier['up_to'] === null ? INF : (float) $tier['up_to'];
            $slice = max(0.0, min($quantity, $bound) - $prev);
            $total += $slice * (int) $tier['unit_minor'];
            $prev = $bound;

            if ($quantity <= $bound) {
                break;
            }
        }

        return Money::ofMinor((int) round($total), $currency);
    }

    /**
     * The per-unit rate (minor) for a quantity: the first tier whose bound covers
     * it, else the last tier.
     *
     * @param  list<array{up_to:int|null,unit_minor:int}>  $tiers
     */
    private static function rateFor(array $tiers, float $quantity): int
    {
        foreach ($tiers as $tier) {
            if ($tier['up_to'] === null || $quantity <= $tier['up_to']) {
                return (int) $tier['unit_minor'];
            }
        }

        return (int) (end($tiers)['unit_minor'] ?? 0);
    }
}
