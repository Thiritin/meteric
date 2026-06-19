<?php

declare(strict_types=1);

namespace Meteric\Models;

use Brick\Money\Money;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Meteric\Enums\Aggregation;
use Meteric\Support\MoneyMath;

/**
 * @property string $key
 * @property Aggregation $aggregation
 * @property string $rate high-precision per-unit rate (major units)
 * @property string $currency
 * @property float $included_qty
 * @property ?int $cap_minor
 */
class MeterDimension extends MetericModel
{
    protected $table = 'meteric_meter_dimensions';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'aggregation' => Aggregation::class,
            'rate' => 'string',   // numeric(20,8) — keep full precision, never float
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

    /** Billable quantity after subtracting the free allowance. */
    public function billableQuantity(float $used): float
    {
        return max(0.0, $used - $this->included_qty);
    }

    /** Charge for $used units: round(billable_qty × rate) to currency minor. */
    public function amountFor(float $used): Money
    {
        $amount = MoneyMath::fromRate($this->billableQuantity($used), $this->rate, $this->currency);

        if ($this->cap_minor !== null) {
            $cap = Money::ofMinor($this->cap_minor, $this->currency);

            return $amount->isGreaterThan($cap) ? $cap : $amount;
        }

        return $amount;
    }
}
