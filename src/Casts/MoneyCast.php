<?php

declare(strict_types=1);

namespace Meteric\Casts;

use Brick\Money\Money;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Casts a (`*_minor` bigint, `currency` char(3)) column pair into a Brick Money.
 *
 * Usage in $casts:  'amount' => MoneyCast::class.':amount_minor,currency'
 * Defaults to {attribute}_minor + sibling `currency` column when no args given.
 *
 * @implements CastsAttributes<Money|null, mixed>
 */
final class MoneyCast implements CastsAttributes
{
    public function __construct(
        private ?string $amountColumn = null,
        private string $currencyColumn = 'currency',
    ) {}

    public function get(Model $model, string $key, mixed $value, array $attributes): ?Money
    {
        $amountCol = $this->amountColumn ?? "{$key}_minor";
        $minor = $attributes[$amountCol] ?? null;
        $currency = $attributes[$this->currencyColumn] ?? null;

        if ($minor === null || $currency === null) {
            return null;
        }

        return Money::ofMinor((int) $minor, $currency);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        $amountCol = $this->amountColumn ?? "{$key}_minor";

        if ($value === null) {
            return [$amountCol => null];
        }

        if (! $value instanceof Money) {
            throw new \InvalidArgumentException("{$key} must be a Brick\\Money\\Money instance.");
        }

        return [
            $amountCol => $value->getMinorAmount()->toInt(),
            $this->currencyColumn => $value->getCurrency()->getCurrencyCode(),
        ];
    }
}
