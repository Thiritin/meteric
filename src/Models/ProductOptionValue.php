<?php

declare(strict_types=1);

namespace Meteric\Models;

use Brick\Money\Money;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One allowed value of a product option, with its own Price (recurring + setup,
 * possibly tiered). For quantity/toggle options there is a single value.
 *
 * @property string $value
 * @property ?string $label
 * @property ?string $price_id
 */
class ProductOptionValue extends MetericModel
{
    protected string $baseTable = 'product_option_values';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'sort' => 'integer',
        ];
    }

    /** @return BelongsTo<ProductOption, $this> */
    public function option(): BelongsTo
    {
        return $this->belongsTo(ProductOption::class, 'option_id');
    }

    /** @return BelongsTo<Price, $this> */
    public function price(): BelongsTo
    {
        return $this->belongsTo(Price::class, 'price_id');
    }

    /** Charge for this value at a quantity (free when it has no price). */
    public function amountFor(float $qty = 1): ?Money
    {
        return $this->price?->amountForQuantity($qty);
    }

    /**
     * Render-ready data for a checkout / upgrade form: the value, its price at
     * the given quantity, and the raw pricing knobs so a client can recompute
     * as the quantity changes.
     *
     * @return array<string,mixed>
     */
    public function toDisplay(float $qty = 1): array
    {
        $price = $this->price;
        $amount = $this->amountFor($qty);

        return [
            'value' => $this->value,
            'label' => $this->label ?? $this->value,
            'price_id' => $this->price_id,
            'amount_minor' => $amount?->getMinorAmount()->toInt() ?? 0,
            'amount' => $amount !== null ? (string) $amount->getAmount() : '0',
            'currency' => $price?->currency,
            'interval' => $price?->interval?->value,
            'pricing_model' => $price?->pricing_model->value,
            'included_qty' => $price?->included_qty,
            'block_size' => $price?->block_size,
            'tiers' => $price !== null ? $price->tiers : [],
            'setup_fee_minor' => $price !== null ? $price->setup_fee_minor : 0,
        ];
    }
}
