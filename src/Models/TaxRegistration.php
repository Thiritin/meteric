<?php

declare(strict_types=1);

namespace Meteric\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * A jurisdiction the merchant is VAT-registered in. Presence of a registration
 * (direct, or an eu_oss row covering the EU) is what authorises charging tax.
 *
 * @property string $country
 * @property string $scheme
 * @property ?string $number
 */
class TaxRegistration extends MetericModel
{
    protected $table = 'meteric_tax_registrations';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'valid_from' => 'immutable_date',
            'valid_to' => 'immutable_date',
            'metadata' => 'array',
        ];
    }

    public function scopeActiveOn(Builder $query, CarbonImmutable $date): Builder
    {
        return $query
            ->where(fn ($q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', $date))
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>', $date));
    }
}
