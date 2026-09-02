<?php

declare(strict_types=1);

namespace Meteric\Models;

use Brick\Money\Money;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Meteric\Enums\PricePurpose;
use Meteric\Support\Models;

/**
 * The catalog side of an Addon: an addon product a base product may be booked
 * with, whether it is required, and how many may be taken. `group_key` is the
 * same key the booked Addon row carries, so members of a group stay mutually
 * exclusive on the item.
 *
 * @property string $product_id
 * @property string $addon_product_id
 * @property ?string $group_key
 * @property bool $required
 * @property bool $active
 * @property ?float $min_qty
 * @property ?float $max_qty
 */
class ProductAddon extends MetericModel
{
    protected string $baseTable = 'product_addons';

    public $timestamps = false;

    protected $guarded = [];

    protected $attributes = ['active' => true];

    protected function casts(): array
    {
        return [
            'required' => 'boolean',
            'active' => 'boolean',
            'min_qty' => 'float',
            'max_qty' => 'float',
            'sort' => 'integer',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Models::for(Product::class), 'product_id');
    }

    /** @return BelongsTo<Product, $this> */
    public function addon(): BelongsTo
    {
        return $this->belongsTo(Models::for(Product::class), 'addon_product_id');
    }

    /**
     * The addon's current price for a base term: same currency, same interval.
     * An `addon` purpose price wins over a `recurring` one. Null when the addon
     * is not priced for that term.
     */
    public function priceFor(Price $term): ?Price
    {
        $product = $this->addon;

        return $product->priceFor($term->currency, PricePurpose::Addon, $term->interval, $term->interval_count)
            ?? $product->priceFor($term->currency, PricePurpose::Recurring, $term->interval, $term->interval_count);
    }

    /**
     * Render-ready data for a checkout form: the addon meta plus its price on
     * the base term at $qty. Relative addons are priced against $base, the
     * base line's period amount. Null when the addon has no price for the term.
     *
     * @return ?array<string,mixed>
     */
    public function toDisplay(Price $term, float $qty = 1, ?Money $base = null): ?array
    {
        $price = $this->priceFor($term);
        if ($price === null) {
            return null;
        }

        $product = $this->addon;
        $amount = $price->isRelative()
            ? $price->amountOfBase($base ?? $term->amountFor(1))
            : $price->amountForQuantity($qty);

        return [
            'product_id' => $product->id,
            'slug' => $product->slug,
            'label' => $product->name,
            'group_key' => $this->group_key,
            'required' => $this->required,
            'min' => $this->min_qty,
            'max' => $this->max_qty,
            ...$price->toDisplay($qty),
            'amount_minor' => $amount->getMinorAmount()->toInt(),
            'amount' => (string) $amount->getAmount(),
        ];
    }
}
