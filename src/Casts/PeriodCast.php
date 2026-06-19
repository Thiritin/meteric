<?php

declare(strict_types=1);

namespace Meteric\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Meteric\Support\Period;

/**
 * Casts a Postgres tstzrange column to/from a Period value object.
 *
 * @implements CastsAttributes<Period|null, mixed>
 */
final class PeriodCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Period
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Period::fromRange((string) $value);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null) {
            return [$key => null];
        }

        if (! $value instanceof Period) {
            throw new \InvalidArgumentException("{$key} must be a Meteric\\Support\\Period instance.");
        }

        return [$key => $value->toRange()];
    }
}
