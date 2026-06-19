<?php

declare(strict_types=1);

namespace Meteric\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Brick\Money\Money;

/**
 * Money arithmetic helpers. The key rule lives here:
 *
 *   rates are high-precision decimals (numeric(20,8)); the moment a rate becomes
 *   a billable amount we multiply in BigDecimal and round to the currency's
 *   minor scale (2 decimals for EUR). Every stored Money is therefore exact and
 *   invoices sum without drift.
 */
final class MoneyMath
{
    /**
     * amount = round(quantity × unitRate) to the currency's minor unit.
     *
     * @param  float|int|string  $quantity  e.g. 1250.5 (GB)
     * @param  float|int|string  $unitRate  e.g. "0.00100000" (per GB, major units)
     */
    public static function fromRate(
        float|int|string $quantity,
        float|int|string $unitRate,
        string $currency,
        RoundingMode $rounding = RoundingMode::HALF_UP,
    ): Money {
        $total = BigDecimal::of((string) $unitRate)->multipliedBy(BigDecimal::of((string) $quantity));

        return Money::of($total, $currency, roundingMode: $rounding);
    }

    /** Round an arbitrary-scale decimal of major units into Money at currency scale. */
    public static function fromMajor(
        float|int|string $major,
        string $currency,
        RoundingMode $rounding = RoundingMode::HALF_UP,
    ): Money {
        return Money::of(BigDecimal::of((string) $major), $currency, roundingMode: $rounding);
    }
}
