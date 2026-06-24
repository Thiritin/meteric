<?php

declare(strict_types=1);

namespace Meteric\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Editable, date-versioned tax rate for a jurisdiction + product category.
 * `rate` is a fraction (0.081 = 8.1%). EU rows source='ibericode'; others manual.
 *
 * @property string $country
 * @property ?string $region
 * @property string $category
 * @property string $rate fraction as numeric string
 * @property string $source
 */
class TaxRate extends MetericModel
{
    protected string $baseTable = 'tax_rates';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'rate' => 'string',           // numeric(8,6) — keep precision
            'effective_from' => 'immutable_date',
            'effective_to' => 'immutable_date',
            'metadata' => 'array',
        ];
    }

    public function rateFraction(): float
    {
        return (float) $this->rate;
    }

    public function scopeActiveOn(Builder $query, CarbonImmutable $date): Builder
    {
        return $query
            ->where('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $date));
    }
}
