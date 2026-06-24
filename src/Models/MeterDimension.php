<?php

declare(strict_types=1);

namespace Meteric\Models;

use Brick\Money\Money;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Meteric\Enums\Aggregation;
use Meteric\Support\MoneyMath;

/**
 * @property string $key
 * @property string $unit display label for the unit (GB, TB, requests)
 * @property Aggregation $aggregation
 * @property string $rate price per unit, or per block when block_size is set
 * @property ?float $block_size
 * @property string $currency
 * @property float $included_qty
 * @property ?int $cap_minor
 */
class MeterDimension extends MetericModel
{
    protected string $baseTable = 'meter_dimensions';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'aggregation' => Aggregation::class,
            'rate' => 'string',   // numeric(20,8) — keep full precision, never float
            'block_size' => 'float',
            'included_qty' => 'float',
            'cap_minor' => 'integer',
            'tiers' => 'array',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /** Overage: used units beyond the free allowance. */
    public function overage(float $used): float
    {
        return max(0.0, $used - $this->included_qty);
    }

    /**
     * Units the rate is charged on. Per unit by default; with block_size set,
     * the number of blocks the overage falls into (a started block counts full).
     */
    public function billedUnits(float $used): float
    {
        $overage = $this->overage($used);

        if ($this->block_size !== null && $this->block_size > 0) {
            return (float) ceil($overage / $this->block_size);
        }

        return $overage;
    }

    /** Charge for $used units: round(billed_units × rate) to currency minor, capped. */
    public function amountFor(float $used): Money
    {
        $amount = MoneyMath::fromRate($this->billedUnits($used), $this->rate, $this->currency);

        if ($this->cap_minor !== null) {
            $cap = Money::ofMinor($this->cap_minor, $this->currency);

            return $amount->isGreaterThan($cap) ? $cap : $amount;
        }

        return $amount;
    }
}
