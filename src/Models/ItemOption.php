<?php

declare(strict_types=1);

namespace Meteric\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Meteric\Enums\OptionType;

/**
 * @property string $key
 * @property ?string $price_id
 * @property OptionType $type
 * @property string $value
 * @property float $quantity
 * @property ?float $min_qty
 * @property ?float $max_qty
 */
class ItemOption extends MetericModel
{
    protected $table = 'meteric_item_options';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'type' => OptionType::class,
            'quantity' => 'float',
            'min_qty' => 'float',
            'max_qty' => 'float',
        ];
    }

    /** @return BelongsTo<SubscriptionItem, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(SubscriptionItem::class, 'item_id');
    }

    /** @return BelongsTo<Price, $this> */
    public function price(): BelongsTo
    {
        return $this->belongsTo(Price::class, 'price_id');
    }

    public function boolValue(): bool
    {
        return filter_var($this->value, FILTER_VALIDATE_BOOLEAN);
    }
}
