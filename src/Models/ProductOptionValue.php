<?php

declare(strict_types=1);

namespace Meteric\Models;

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
    protected $table = 'meteric_product_option_values';

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
}
