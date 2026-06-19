<?php

declare(strict_types=1);

namespace Meteric\Support;

use Illuminate\Support\Facades\DB;

/**
 * Small migration helpers for the PostgreSQL bits Laravel's Blueprint can't
 * express. Kept tiny and named so migrations read cleanly and constraints stay
 * easy to ALTER (drop/add a CHECK) — unlike native enum types.
 */
final class Pg
{
    /** Add a CHECK that restricts a string column to a PHP enum's backing values. */
    public static function enumCheck(string $table, string $column, string $enumClass, bool $nullable = false): void
    {
        $values = array_map(static fn ($case) => $case->value, $enumClass::cases());
        $list = "'".implode("','", $values)."'";
        $null = $nullable ? "{$column} IS NULL OR " : '';

        self::check($table, "{$table}_{$column}_check", "{$null}{$column} IN ({$list})");
    }

    /** Add an arbitrary named CHECK constraint. */
    public static function check(string $table, string $name, string $expression): void
    {
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$expression})");
    }

    /** ISO-4217-shaped currency column guard. */
    public static function currencyCheck(string $table, string $column = 'currency'): void
    {
        self::check($table, "{$table}_{$column}_fmt", "{$column} ~ '^[A-Z]{3}$'");
    }
}
