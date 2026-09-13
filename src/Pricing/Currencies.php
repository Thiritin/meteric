<?php

declare(strict_types=1);

namespace Meteric\Pricing;

/**
 * The currencies an installation trades in, and which one a buyer is priced in.
 *
 * **There is no exchange rate in meteric and nothing is ever converted.** A
 * price in a second currency is a second price row typed by hand. That is the
 * whole point of the map: a market that earns less can be sold to more cheaply,
 * which a converted amount can never express.
 *
 * A country the map does not name is priced in the default currency, and so is
 * one mapped to a currency `meteric.currencies` no longer lists. A map entry is
 * therefore safe to leave behind when a currency is withdrawn.
 */
final class Currencies
{
    public static function default(): string
    {
        return strtoupper(trim((string) config('meteric.currency', 'EUR')));
    }

    /**
     * Every currency this installation prices in, the default first. One entry
     * means there is no choice to make and a currency control has nothing to
     * offer.
     *
     * @return list<string>
     */
    public static function configured(): array
    {
        $codes = array_filter(array_map(
            static fn (mixed $code): ?string => is_string($code) && trim($code) !== '' ? strtoupper(trim($code)) : null,
            (array) config('meteric.currencies', []),
        ));

        return array_values(array_unique([self::default(), ...$codes]));
    }

    /**
     * The currency a buyer in this country is priced in. Unknown, unmapped and
     * withdrawn all resolve to the default currency rather than failing: a
     * visitor is always quotable.
     */
    public static function forCountry(?string $country): string
    {
        if ($country === null || trim($country) === '') {
            return self::default();
        }

        $mapped = ((array) config('meteric.country_currencies', []))[strtoupper(trim($country))] ?? null;

        if (! is_string($mapped) || trim($mapped) === '') {
            return self::default();
        }

        $mapped = strtoupper(trim($mapped));

        return in_array($mapped, self::configured(), true) ? $mapped : self::default();
    }
}
