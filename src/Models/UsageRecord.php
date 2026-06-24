<?php

declare(strict_types=1);

namespace Meteric\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Meteric\Casts\PeriodCast;

/**
 * @property float $quantity
 * @property CarbonImmutable $occurred_at
 */
class UsageRecord extends MetericModel
{
    protected string $baseTable = 'usage_records';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'quantity' => 'float',
            'occurred_at' => 'immutable_datetime',
            'window' => PeriodCast::class,
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<SubscriptionItem, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(SubscriptionItem::class, 'item_id');
    }

    /** @return BelongsTo<MeterDimension, $this> */
    public function dimension(): BelongsTo
    {
        return $this->belongsTo(MeterDimension::class, 'dimension_id');
    }

    public function scopeUnbilled(Builder $query): Builder
    {
        return $query->whereNull('charge_id');
    }
}
