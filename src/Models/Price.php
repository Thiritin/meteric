<?php

declare(strict_types=1);

namespace Meteric\Models;

use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Meteric\Casts\MoneyCast;
use Meteric\Enums\BillingMode;
use Meteric\Enums\Interval;
use Meteric\Enums\PricePurpose;
use Meteric\Enums\PricingModel;
use Meteric\Support\MoneyMath;
use Meteric\Support\RecurrenceRule;

/**
 * @property string $id
 * @property string $product_id
 * @property string $currency
 * @property int $amount_minor
 * @property Money $amount
 * @property ?string $unit_rate high-precision per-unit rate (major units, sub-cent)
 * @property PricePurpose $purpose
 * @property PricingModel $pricing_model
 * @property ?Interval $interval
 * @property ?int $interval_count
 * @property BillingMode $billing_mode
 * @property int $setup_fee_minor
 * @property ?int $cap_minor
 * @property array $tiers
 * @property bool $tax_inclusive
 */
class Price extends MetericModel
{
    protected $table = 'meteric_prices';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'amount' => MoneyCast::class.':amount_minor,currency',
            'amount_minor' => 'integer',
            'unit_rate' => 'string',   // numeric(20,8) — preserve precision
            'purpose' => PricePurpose::class,
            'pricing_model' => PricingModel::class,
            'interval' => Interval::class,
            'interval_count' => 'integer',
            'billing_mode' => BillingMode::class,
            'setup_fee_minor' => 'integer',
            'cap_minor' => 'integer',
            'min_charge_minor' => 'integer',
            'tiers' => 'array',
            'tax_inclusive' => 'boolean',
            'valid_from' => 'immutable_datetime',
            'valid_to' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function recurrence(): RecurrenceRule
    {
        return new RecurrenceRule($this->interval, $this->interval_count);
    }

    public function isRecurring(): bool
    {
        return $this->recurrence()->isRecurring();
    }

    public function hasSetupFee(): bool
    {
        return $this->setup_fee_minor > 0;
    }

    public function setupFee(): Money
    {
        return Money::ofMinor($this->setup_fee_minor, $this->currency);
    }

    public function cap(): ?Money
    {
        return $this->cap_minor === null ? null : Money::ofMinor($this->cap_minor, $this->currency);
    }

    /**
     * Charge for $quantity units of a per-unit/usage price: round(qty × unit_rate)
     * to currency minor. Falls back to the flat amount when no rate is set.
     */
    public function amountFor(float|int|string $quantity): Money
    {
        if ($this->unit_rate === null) {
            return $this->amount->multipliedBy((string) $quantity, RoundingMode::HALF_UP);
        }

        return MoneyMath::fromRate($quantity, $this->unit_rate, $this->currency);
    }
}
