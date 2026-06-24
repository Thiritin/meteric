<?php

declare(strict_types=1);

namespace Meteric\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Discount extends MetericModel
{
    protected string $baseTable = 'discounts';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['remaining_cycles' => 'integer'];
    }

    /** @return BelongsTo<Coupon, $this> */
    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class, 'coupon_id');
    }

    public function target(): MorphTo
    {
        return $this->morphTo('target', 'target_type', 'target_id');
    }
}
