<?php

declare(strict_types=1);

namespace Meteric\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Meteric\Enums\OptionType;

/**
 * @property string $key
 * @property OptionType $type
 * @property string $value
 * @property float $quantity
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
