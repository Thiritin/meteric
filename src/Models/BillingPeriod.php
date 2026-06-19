<?php

declare(strict_types=1);

namespace Meteric\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Meteric\Casts\PeriodCast;
use Meteric\Support\Period;

/**
 * Ledger of fully-billed windows. The GiST EXCLUDE constraint on this table is
 * the DB-level guarantee that no window is ever billed twice per item+dimension.
 *
 * @property ?Period $covers
 */
class BillingPeriod extends MetericModel
{
    protected $table = 'meteric_billing_periods';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'covers' => PeriodCast::class,
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<SubscriptionItem, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(SubscriptionItem::class, 'item_id');
    }
}
