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
use Meteric\Pricing\Tiers;
use Meteric\Support\Models;
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
 * @property int $min_charge_minor
 * @property float $included_qty
 * @property ?float $block_size
 * @property ?float $percent
 * @property array $tiers
 * @property bool $tax_inclusive
 */
class Price extends MetericModel
{
    protected string $baseTable = 'prices';

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
            'included_qty' => 'float',
            'block_size' => 'float',
            'percent' => 'float',
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
        return $this->belongsTo(Models::for(Product::class), 'product_id');
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
     * Charge for $quantity units.
     *
     *  - Volume / Tiered: priced from the `tiers` table (quantity discounts).
     *  - per-unit with a unit_rate: round(qty × unit_rate).
     *  - otherwise: flat amount × qty.
     */
    public function amountFor(float|int|string $quantity): Money
    {
        $qty = (float) $quantity;
        $tiers = $this->tiers ?? [];

        if ($tiers !== [] && $this->pricing_model === PricingModel::Volume) {
            return Tiers::volume($tiers, $qty, $this->currency);
        }

        if ($tiers !== [] && $this->pricing_model === PricingModel::Tiered) {
            return Tiers::graduated($tiers, $qty, $this->currency);
        }

        if ($this->unit_rate === null) {
            return $this->amount->multipliedBy((string) $quantity, RoundingMode::HALF_UP);
        }

        return MoneyMath::fromRate($quantity, $this->unit_rate, $this->currency);
    }

    /**
     * Billable units for a quantity after the free allowance and block rounding,
     * the same shape as a usage meter: subtract included_qty, then round up to
     * whole blocks when block_size is set.
     */
    public function billedUnits(float $quantity): float
    {
        $effective = max(0.0, $quantity - $this->included_qty);

        if ($this->block_size !== null && $this->block_size > 0) {
            return (float) ceil($effective / $this->block_size);
        }

        return $effective;
    }

    /**
     * Charge for a quantity with the usage-style knobs applied: free allowance
     * (included_qty), block rounding (block_size), then the tier/flat pricing of
     * amountFor(), clamped to min_charge_minor and cap_minor. Use this for
     * configurable options and addons so their settings match metered usage.
     */
    public function isRelative(): bool
    {
        return $this->pricing_model === PricingModel::Relative;
    }

    /** The percent without trailing zeros, e.g. "20" or "12.5". */
    public function percentLabel(): string
    {
        return rtrim(rtrim(number_format((float) $this->percent, 4, '.', ''), '0'), '.');
    }

    /**
     * Relative pricing: a percentage of a base amount (the owning item's period
     * amount). Allowance, blocks, tiers, and caps do not apply.
     */
    public function amountOfBase(Money $base): Money
    {
        $baseCurrency = $base->getCurrency()->getCurrencyCode();
        if ($this->currency !== $baseCurrency) {
            throw new \InvalidArgumentException(
                "Relative price currency {$this->currency} does not match base currency {$baseCurrency}."
            );
        }

        if ($this->percent === null || $this->percent <= 0) {
            return Money::ofMinor(0, $base->getCurrency());
        }

        return $base->multipliedBy($this->percent / 100, RoundingMode::HALF_UP);
    }

    public function amountForQuantity(float $quantity): Money
    {
        $amount = $this->amountFor($this->billedUnits($quantity));

        if ($this->min_charge_minor > 0) {
            $min = Money::ofMinor($this->min_charge_minor, $this->currency);
            if ($amount->isLessThan($min)) {
                $amount = $min;
            }
        }

        $cap = $this->cap();
        if ($cap !== null && $amount->isGreaterThan($cap)) {
            $amount = $cap;
        }

        return $amount;
    }
}
