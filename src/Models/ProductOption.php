<?php

declare(strict_types=1);

namespace Meteric\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Meteric\Enums\OptionType;

/**
 * A configurable option a product offers (dropdown/radio/quantity/toggle).
 * Its values point at Prices, so per-term and tiered pricing come for free.
 *
 * @property string $key
 * @property ?string $label
 * @property OptionType $type
 * @property bool $required
 * @property ?float $min_qty
 * @property ?float $max_qty
 */
class ProductOption extends MetericModel
{
    protected string $baseTable = 'product_options';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'type' => OptionType::class,
            'required' => 'boolean',
            'min_qty' => 'float',
            'max_qty' => 'float',
            'sort' => 'integer',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /** @return HasMany<ProductOptionValue, $this> */
    public function values(): HasMany
    {
        return $this->hasMany(ProductOptionValue::class, 'option_id')->orderBy('sort');
    }

    /**
     * Render-ready data for a form control (dropdown/quantity/toggle): the option
     * meta plus each value priced at $qty.
     *
     * @return array<string,mixed>
     */
    public function toDisplay(float $qty = 1): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label ?? $this->key,
            'type' => $this->type->value,
            'required' => $this->required,
            'min' => $this->min_qty,
            'max' => $this->max_qty,
            'values' => $this->values->map(fn (ProductOptionValue $v): array => $v->toDisplay($qty))->all(),
        ];
    }
}
