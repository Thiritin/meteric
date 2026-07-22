<?php

declare(strict_types=1);

namespace Meteric\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Meteric\Casts\PeriodCast;
use Meteric\Enums\AnchorMode;
use Meteric\Enums\FirstPeriodPolicy;
use Meteric\Enums\SubscriptionState;
use Meteric\Support\Models;
use Meteric\Support\Period;

/**
 * @property string $id
 * @property string $account_id
 * @property string $currency
 * @property SubscriptionState $state
 * @property AnchorMode $anchor_mode
 * @property ?int $anchor_day
 * @property FirstPeriodPolicy $first_period
 * @property ?Period $current_period
 * @property ?CarbonImmutable $trial_end
 * @property ?CarbonImmutable $cancel_at
 * @property ?CarbonImmutable $canceled_at
 */
class Subscription extends MetericModel
{
    protected string $baseTable = 'subscriptions';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'state' => SubscriptionState::class,
            'anchor_mode' => AnchorMode::class,
            'anchor_day' => 'integer',
            'first_period' => FirstPeriodPolicy::class,
            'current_period' => PeriodCast::class,
            'trial_end' => 'immutable_datetime',
            'canceled_at' => 'immutable_datetime',
            'cancel_at' => 'immutable_datetime',
            'version' => 'integer',
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<BillingAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Models::for(BillingAccount::class), 'account_id');
    }

    public function customer(): MorphTo
    {
        return $this->morphTo('customer', 'customer_type', 'customer_id');
    }

    /** @return HasMany<SubscriptionItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(Models::for(SubscriptionItem::class), 'subscription_id');
    }

    /** @return HasMany<Charge, $this> */
    public function charges(): HasMany
    {
        return $this->hasMany(Models::for(Charge::class), 'subscription_id');
    }

    public function isBillable(): bool
    {
        return $this->state->isBillable();
    }

    public function isOnTrial(): bool
    {
        return $this->trial_end !== null && $this->trial_end->isFuture();
    }

    /** Earliest item period end = subscription-level renewal moment. */
    public function nextRenewalAt(): ?CarbonImmutable
    {
        return $this->items
            ->map(fn (SubscriptionItem $i) => $i->current_period?->end)
            ->filter()
            ->min();
    }

    public function scopeDueForRenewal($query, CarbonImmutable $at)
    {
        return $query->whereIn('state', ['active', 'trialing', 'past_due'])
            ->whereRaw('upper(current_period) <= ?', [$at]);
    }
}
