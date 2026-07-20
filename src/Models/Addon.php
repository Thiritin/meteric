<?php

declare(strict_types=1);

namespace Meteric\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Meteric\Enums\ItemState;
use Meteric\Support\Models;

/**
 * @property string $id
 * @property float $quantity
 * @property ItemState $state
 * @property ?string $group_key
 * @property array $metadata
 */
class Addon extends MetericModel
{
    protected string $baseTable = 'addons';

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
        return $this->belongsTo(Models::for(SubscriptionItem::class), 'item_id');
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
}
