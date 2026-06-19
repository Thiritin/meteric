<?php

declare(strict_types=1);

namespace Meteric\Models;

use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Meteric\Enums\DiscountType;

/**
 * @property string $code
 * @property DiscountType $type
 * @property float $value
 * @property ?int $value_minor
 * @property ?int $max_redemptions
 * @property int $redeemed_count
 * @property ?CarbonImmutable $valid_from
 * @property ?CarbonImmutable $valid_to
 */
class Coupon extends MetericModel
{
    protected $table = 'meteric_coupons';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'type' => DiscountType::class,
            'value' => 'float',
            'value_minor' => 'integer',
            'duration_cycles' => 'integer',
            'max_redemptions' => 'integer',
            'redeemed_count' => 'integer',
            'valid_from' => 'immutable_datetime',
            'valid_to' => 'immutable_datetime',
            'exclusive' => 'boolean',
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<Discount, $this> */
    public function discounts(): HasMany
    {
        return $this->hasMany(Discount::class, 'coupon_id');
    }

    public function isValidAt(CarbonImmutable $at): bool
    {
        if ($this->valid_from && $at < $this->valid_from) {
            return false;
        }
        if ($this->valid_to && $at >= $this->valid_to) {
            return false;
        }
        if ($this->max_redemptions !== null && $this->redeemed_count >= $this->max_redemptions) {
            return false;
        }

        return true;
    }

    /** Discount amount (negative) to apply to a base. */
    public function discountFor(Money $base): Money
    {
        return match ($this->type) {
            DiscountType::Percent => $base->multipliedBy($this->value / 100, RoundingMode::HALF_UP)->negated(),
            DiscountType::Fixed => Money::ofMinor($this->value_minor ?? 0, $base->getCurrency())->negated(),
        };
    }
}
