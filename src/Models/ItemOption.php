<?php

declare(strict_types=1);

namespace Meteric\Models;

use Brick\Money\Money;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Meteric\Enums\OptionType;

/**
 * @property string $key
 * @property ?string $price_id
 * @property OptionType $type
 * @property string $value
 * @property ?string $label
 * @property float $quantity
 * @property ?float $min_qty
 * @property ?float $max_qty
 */
class ItemOption extends MetericModel
{
    protected string $baseTable = 'item_options';

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

    /** The recurring charge this option adds per period (free when it has no price). */
    public function amount(): ?Money
    {
        return $this->price?->amountForQuantity((float) $this->quantity);
    }

    /**
     * Render-ready data for a service page: the current selection and its cost.
     *
     * @return array<string,mixed>
     */
    public function toDisplay(): array
    {
        $amount = $this->amount();

        return [
            'key' => $this->key,
            'value' => $this->value,
            'label' => $this->label ?? $this->value,
            'quantity' => $this->quantity,
            'amount_minor' => $amount?->getMinorAmount()->toInt() ?? 0,
            'amount' => $amount !== null ? (string) $amount->getAmount() : '0',
            'currency' => $this->price?->currency,
        ];
    }
}
