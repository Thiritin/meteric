<?php

declare(strict_types=1);

namespace Meteric\Models;

use Brick\Money\Money;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Meteric\Casts\PeriodCast;
use Meteric\Enums\CommitmentState;
use Meteric\Enums\Interval;
use Meteric\Support\Period;

/**
 * @property Interval $term_interval
 * @property int $term_count
 * @property int $rate_minor
 * @property string $currency
 * @property CommitmentState $state
 * @property ?Period $term
 */
class Commitment extends MetericModel
{
    protected $table = 'meteric_commitments';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'term_interval' => Interval::class,
            'term_count' => 'integer',
            'upfront_minor' => 'integer',
            'rate_minor' => 'integer',
            'term' => PeriodCast::class,
            'early_term' => 'array',
            'state' => CommitmentState::class,
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<SubscriptionItem, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(SubscriptionItem::class, 'item_id');
    }

    public function isActive(): bool
    {
        return $this->state === CommitmentState::Active;
    }

    public function committedRate(): Money
    {
        return Money::ofMinor($this->rate_minor, $this->currency);
    }
}
