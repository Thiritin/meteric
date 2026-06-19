<?php

declare(strict_types=1);

namespace Meteric\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Meteric\Enums\ItemState;

/**
 * @property string $id
 * @property float $quantity
 * @property ItemState $state
 * @property ?string $group_key
 * @property array $metadata
 */
class Addon extends MetericModel
{
    protected $table = 'meteric_addons';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'quantity' => 'float',
            'state' => ItemState::class,
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<SubscriptionItem, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(SubscriptionItem::class, 'item_id');
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /** @return BelongsTo<Price, $this> */
    public function price(): BelongsTo
    {
        return $this->belongsTo(Price::class, 'price_id');
    }
}
