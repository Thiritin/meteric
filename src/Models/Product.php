<?php

declare(strict_types=1);

namespace Meteric\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Meteric\Casts\ProductConfigCast;
use Meteric\Enums\DowngradePolicy;
use Meteric\Enums\PricePurpose;
use Meteric\Enums\PricingModel;
use Meteric\Support\Models;

/**
 * @property string $id
 * @property string $type
 * @property string $slug
 * @property string $name
 * @property PricingModel $pricing_model
 * @property bool $is_proratable
 * @property array $config
 */
class Product extends MetericModel
{
    protected string $baseTable = 'products';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'pricing_model' => PricingModel::class,
            'is_proratable' => 'boolean',
            'active' => 'boolean',
            'config' => ProductConfigCast::class,
            'metadata' => 'array',
        ];
    }

    public function billable(): MorphTo
    {
        return $this->morphTo('billable', 'billable_type', 'billable_id');
    }

    /** @return HasMany<Price, $this> */
    public function prices(): HasMany
    {
        return $this->hasMany(Models::for(Price::class), 'product_id');
    }

    /** @return HasMany<MeterDimension, $this> */
    public function meterDimensions(): HasMany
    {
        return $this->hasMany(Models::for(MeterDimension::class), 'product_id');
    }

    /** @return HasMany<ProductOption, $this> */
    public function options(): HasMany
    {
        return $this->hasMany(Models::for(ProductOption::class), 'product_id')->orderBy('sort');
    }

    /**
     * The configurable-option catalog as render-ready data for a checkout or
     * upgrade/downgrade form: every option with its values priced at $qty. JSON
     * encode it straight to the frontend.
     *
     * @return list<array<string,mixed>>
     */
    public function optionCatalog(float $qty = 1): array
    {
        return $this->options()->with('values.price')->get()
            ->map(fn (ProductOption $o): array => $o->toDisplay($qty))
            ->all();
    }

    public function priceFor(string $currency, PricePurpose $purpose = PricePurpose::Recurring): ?Price
    {
        return $this->prices()
            ->whereNull('valid_to')
            ->where('currency', $currency)
            ->where('purpose', $purpose->value)
            ->latest('valid_from')
            ->first();
    }

    public function isMetered(): bool
    {
        return $this->pricing_model->isUsageBased();
    }

    /** Downgrade policy for this product (config 'downgrade' key); defaults to defer. */
    public function downgradePolicy(): DowngradePolicy
    {
        return DowngradePolicy::tryFrom($this->config['downgrade'] ?? '')
            ?? DowngradePolicy::Defer;
    }

    /** Notice required to cancel a contract: days before the term boundary (config 'cancel_notice_days'); 0 = cancel any time. */
    public function cancelNoticeDays(): int
    {
        return max(0, (int) ($this->config['cancel_notice_days'] ?? 0));
    }
}
