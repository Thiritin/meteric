<?php

declare(strict_types=1);

namespace Meteric\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Meteric\Casts\PeriodCast;
use Meteric\Support\Period;

/**
 * @property float $included_qty
 * @property float $consumed_qty
 * @property ?Period $period
 */
class Allowance extends MetericModel
{
    protected string $baseTable = 'allowances';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'included_qty' => 'float',
            'consumed_qty' => 'float',
            'period' => PeriodCast::class,
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

    public function remaining(): float
    {
        return max(0.0, $this->included_qty - $this->consumed_qty);
    }
}
