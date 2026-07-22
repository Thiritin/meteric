<?php

declare(strict_types=1);

namespace Meteric\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Model registry. Every Meteric model can be swapped for a host-app subclass so
 * the consumer can add relationships, casts, and behaviour. Register overrides
 * once (e.g. in a service provider's register()):
 *
 *   Meteric::useInvoiceModel(App\Models\Invoice::class);
 *
 * Internally the engine never news up a model class directly; it resolves it
 * here so both instantiation and relationships honour the override. Overrides
 * must extend the model they replace.
 */
final class Models
{
    /**
     * Registered overrides, keyed by the base model FQCN.
     *
     * @var array<class-string<Model>, class-string<Model>>
     */
    private static array $map = [];

    /**
     * Point a base model at a host-app subclass.
     *
     * @param  class-string<Model>  $base
     * @param  class-string<Model>  $override
     */
    public static function swap(string $base, string $override): void
    {
        if ($override !== $base && ! is_subclass_of($override, $base)) {
            throw new \InvalidArgumentException("[{$override}] must extend [{$base}] to replace it.");
        }

        self::$map[$base] = $override;
    }

    /**
     * The configured class for a base model (the override, or the base itself).
     *
     * @template T of Model
     *
     * @param  class-string<T>  $base
     * @return class-string<T>
     */
    public static function for(string $base): string
    {
        /** @var class-string<T> */
        return self::$map[$base] ?? $base;
    }

    /**
     * A fresh query builder for the configured class of a base model.
     *
     * @template T of Model
     *
     * @param  class-string<T>  $base
     * @return Builder<T>
     */
    public static function query(string $base): Builder
    {
        $class = self::for($base);

        /** @var Builder<T> $query */
        $query = $class::query();

        return $query;
    }

    /** Forget all overrides (test isolation). */
    public static function reset(): void
    {
        self::$map = [];
    }
}
