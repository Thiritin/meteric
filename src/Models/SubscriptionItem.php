<?php

declare(strict_types=1);

namespace Billify\Models;

use Billify\Casts\PeriodCast;
use Billify\Enums\BillingMode;
use Billify\Enums\ItemState;
use Billify\Support\Period;
use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property string $id
 * @property float $quantity
 * @property ?BillingMode $billing_mode
 * @property ItemState $state
 * @property ?Period $current_period
 * @property ?array $pending_change
 */
class SubscriptionItem extends BillifyModel
{
    protected $table = 'billify_subscription_items';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'quantity' => 'float',
            'billing_mode' => BillingMode::class,
            'state' => ItemState::class,
            'current_period' => PeriodCast::class,
            'activated_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'pending_change' => 'array',
            'version' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class, 'subscription_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function price(): BelongsTo
    {
        return $this->belongsTo(Price::class, 'price_id');
    }

    public function resource(): MorphTo
    {
        return $this->morphTo('resource', 'resource_type', 'resource_id');
    }

    public function addons(): HasMany
    {
        return $this->hasMany(Addon::class, 'item_id');
    }

    public function options(): HasMany
    {
        return $this->hasMany(ItemOption::class, 'item_id');
    }

    public function commitment(): HasOne
    {
        return $this->hasOne(Commitment::class, 'item_id');
    }

    public function usageRecords(): HasMany
    {
        return $this->hasMany(UsageRecord::class, 'item_id');
    }

    /** Effective billing mode (item override → price → in-advance default). */
    public function billingMode(): BillingMode
    {
        return $this->billing_mode ?? $this->price?->billing_mode ?? BillingMode::InAdvance;
    }

    /** Amount for one full period: committed rate while under an active commitment, else price. */
    public function periodAmount(): Money
    {
        $commitment = $this->commitment;
        if ($commitment && $commitment->isActive()) {
            return $commitment->committedRate()->multipliedBy((string) $this->quantity, RoundingMode::HALF_UP);
        }

        return $this->price->amountFor((float) $this->quantity);
    }

    public function hasPendingChange(): bool
    {
        return ! empty($this->pending_change);
    }
}
