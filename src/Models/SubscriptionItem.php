<?php

declare(strict_types=1);

namespace Meteric\Models;

use Brick\Money\Money;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Meteric\Casts\PeriodCast;
use Meteric\Enums\BillingMode;
use Meteric\Enums\ItemState;
use Meteric\Support\Models;
use Meteric\Support\Period;

/**
 * @property string $id
 * @property string $subscription_id
 * @property string $product_id
 * @property string $price_id
 * @property ?string $label
 * @property ?string $group
 * @property float $quantity
 * @property ?BillingMode $billing_mode
 * @property ItemState $state
 * @property ?Period $current_period
 * @property ?array $pending_change
 */
class SubscriptionItem extends MetericModel
{
    protected string $baseTable = 'subscription_items';

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

    /** @return BelongsTo<Subscription, $this> */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Models::for(Subscription::class), 'subscription_id');
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Models::for(Product::class), 'product_id');
    }

    /** @return BelongsTo<Price, $this> */
    public function price(): BelongsTo
    {
        return $this->belongsTo(Models::for(Price::class), 'price_id');
    }

    public function resource(): MorphTo
    {
        return $this->morphTo('resource', 'resource_type', 'resource_id');
    }

    /** @return HasMany<Addon, $this> */
    public function addons(): HasMany
    {
        return $this->hasMany(Models::for(Addon::class), 'item_id');
    }

    /** @return HasMany<ItemOption, $this> */
    public function options(): HasMany
    {
        return $this->hasMany(Models::for(ItemOption::class), 'item_id');
    }

    /** @return HasMany<UsageRecord, $this> */
    public function usageRecords(): HasMany
    {
        return $this->hasMany(Models::for(UsageRecord::class), 'item_id');
    }

    /** Invoice line title: "Product - label" (e.g. "VPS XL - vps12345.example"), or just the product name. */
    public function lineTitle(): string
    {
        return $this->label !== null
            ? $this->product->name.' - '.$this->label
            : $this->product->name;
    }

    /** Effective billing mode (item override → price → in-advance default). */
    public function billingMode(): BillingMode
    {
        return $this->billing_mode ?? $this->price->billing_mode ?? BillingMode::InAdvance;
    }

    /**
     * The current billing cycle window. Query your usage API for this range, then
     * record the cycle-to-date value with 'last' aggregation: rollup takes the
     * latest report as the cycle total, and the next cycle starts fresh.
     */
    public function billingCycle(): ?Period
    {
        return $this->current_period;
    }

    /** Amount for one full period at the item's quantity. */
    public function periodAmount(): Money
    {
        return $this->price->amountFor((float) $this->quantity);
    }

    public function hasPendingChange(): bool
    {
        return ! empty($this->pending_change);
    }
}
