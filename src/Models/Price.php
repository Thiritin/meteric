<?php

declare(strict_types=1);

namespace Meteric\Models;

use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Meteric\Casts\MoneyCast;
use Meteric\Contracts\CatalogDefaults;
use Meteric\Enums\BillingMode;
use Meteric\Enums\Interval;
use Meteric\Enums\PricePurpose;
use Meteric\Enums\PriceScope;
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
 * @property PriceScope $scope
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
 * @property ?int $minimum_term_periods null = take the product's
 * @property ?int $cancel_notice_days null = take the product's
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
            'scope' => PriceScope::class,
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
            'minimum_term_periods' => 'integer',
            'cancel_notice_days' => 'integer',
            'tiers' => 'array',
            'tax_inclusive' => 'boolean',
            'valid_from' => 'immutable_datetime',
            'valid_to' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }

    /**
     * Catalog prices only: what a product actually sells at.
     *
     * **Every listing, report and export of prices goes through this.** A price
     * with `scope = override` belongs to one subscription item and is not on
     * sale; excluding it by convention in each query is how one query
     * eventually forgets and quotes a customer someone else's bespoke price.
     *
     * @param  Builder<Price>  $query
     */
    public function scopeCatalog(Builder $query): void
    {
        $query->where('scope', PriceScope::Catalog->value);
    }

    /**
     * A copy of this price billing a different amount, belonging to one item
     * rather than to the catalog.
     *
     * Everything else is copied deliberately - the product, the interval, the
     * billing mode, the purpose, the model - so an override behaves exactly as
     * the price it replaces in every calculation that reads it. Only the amount
     * differs, which is the whole point and the only thing that may.
     */
    public function asOverride(int $amountMinor): Price
    {
        return static::query()->create([
            ...$this->only([
                'product_id', 'currency', 'unit_rate', 'purpose', 'pricing_model',
                'interval', 'interval_count', 'billing_mode', 'setup_fee_minor',
                'cap_minor', 'min_charge_minor', 'included_qty', 'block_size',
                'percent', 'tiers', 'tax_inclusive', 'minimum_term_periods',
                'cancel_notice_days',
            ]),
            'amount_minor' => $amountMinor,
            'scope' => PriceScope::Override->value,
            'metadata' => ['overrides_price_id' => $this->id],
        ]);
    }

    public function isOverride(): bool
    {
        return $this->scope === PriceScope::Override;
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

    /**
     * Periods a sale on this price is committed for before it may be
     * cancelled. The product's value unless this row sets its own, so a
     * product sold monthly and yearly can commit each term differently, and
     * the deployment's default where neither states a figure.
     */
    public function minimumTerm(): int
    {
        return max(0, $this->minimum_term_periods ?? $this->product?->minimumTerm() ?? app(CatalogDefaults::class)->minimumTermPeriods() ?? 0);
    }

    /**
     * Days of notice a cancellation of a sale on this price needs. The
     * product's value unless this row sets its own, so a product sold monthly
     * and yearly can ask a different notice for each term.
     *
     * A subscription item points at the row it was sold on and price rows are
     * superseded rather than edited, so this is the notice the customer agreed
     * to rather than whatever the catalog says today.
     */
    public function cancelNoticeDays(): int
    {
        return max(0, $this->cancel_notice_days ?? $this->product?->cancelNoticeDays() ?? app(CatalogDefaults::class)->cancelNoticeDays() ?? 0);
    }

    /**
     * The moment a term starting at $from expires, or null where there is no
     * term to expire. The recurrence applied `minimumTerm()` times, so twelve
     * periods of a quarterly price is three years, not twelve.
     */
    public function minimumTermEnd(CarbonImmutable $from): ?CarbonImmutable
    {
        $periods = $this->minimumTerm();
        $rule = $this->recurrence();

        if ($periods < 1 || ! $rule->isRecurring()) {
            return null;
        }

        return $rule->interval->add($from, $rule->count * $periods);
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

        return $base->multipliedBy((string) ($this->percent / 100), RoundingMode::HALF_UP);
    }

    /**
     * Charge for a quantity with the usage-style knobs applied: free allowance
     * (included_qty), block rounding (block_size), then the tier/flat pricing of
     * amountFor(), clamped to min_charge_minor and cap_minor. Use this for
     * configurable options and addons so their settings match metered usage.
     */
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

    /**
     * Render-ready data for a checkout form: the price at $qty plus the raw
     * pricing knobs so a client can recompute as the quantity changes.
     *
     * @return array<string,mixed>
     */
    public function toDisplay(float $qty = 1): array
    {
        $amount = $this->isRelative() ? Money::ofMinor(0, $this->currency) : $this->amountForQuantity($qty);

        return [
            'price_id' => $this->id,
            'currency' => $this->currency,
            'purpose' => $this->purpose->value,
            'pricing_model' => $this->pricing_model->value,
            'interval' => $this->interval?->value,
            'interval_count' => $this->interval_count,
            'amount_minor' => $amount->getMinorAmount()->toInt(),
            'amount' => (string) $amount->getAmount(),
            'unit_rate' => $this->unit_rate,
            'percent' => $this->percent,
            'included_qty' => $this->included_qty,
            'block_size' => $this->block_size,
            'tiers' => $this->tiers ?? [],
            'setup_fee_minor' => $this->setup_fee_minor,
        ];
    }
}
