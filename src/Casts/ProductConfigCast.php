<?php

declare(strict_types=1);

namespace Meteric\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Meteric\Enums\DowngradePolicy;

/**
 * Casts and validates a Product's `config`. The keys the package reads are
 * checked on write so a bad value never reaches the database; any other keys
 * (a host's own settings) pass through untouched.
 *
 * @implements CastsAttributes<array<string,mixed>, array<string,mixed>>
 */
final class ProductConfigCast implements CastsAttributes
{
    /**
     * @param  array<string,mixed>  $attributes
     * @return array<string,mixed>
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null) {
            return [];
        }

        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string,mixed>  $attributes
     * @return array<string,string>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        $config = is_array($value) ? $value : [];

        if (array_key_exists('downgrade', $config) && DowngradePolicy::tryFrom((string) $config['downgrade']) === null) {
            throw new \InvalidArgumentException("Invalid product config 'downgrade': must be one of ".implode(', ', array_column(DowngradePolicy::cases(), 'value')).'.');
        }

        if (array_key_exists('cancel_notice_days', $config)) {
            $days = $config['cancel_notice_days'];
            if (! is_numeric($days) || (int) $days < 0) {
                throw new \InvalidArgumentException("Invalid product config 'cancel_notice_days': must be a non-negative integer.");
            }
        }

        return [$key => (string) json_encode($config)];
    }
}
