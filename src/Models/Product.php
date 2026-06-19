<?php

declare(strict_types=1);

namespace Billify\Models;

use Billify\Enums\DowngradePolicy;
use Billify\Enums\PricePurpose;
use Billify\Enums\PricingModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property string $id
 * @property string $type
 * @property string $slug
 * @property string $name
 * @property PricingModel $pricing_model
 * @property bool $is_proratable
 * @property array $config
 */
class Product extends BillifyModel
{
    protected $table = 'billify_products';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'pricing_model' => PricingModel::class,
            'is_proratable' => 'boolean',
            'active' => 'boolean',
            'config' => 'array',
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
        return $this->hasMany(Price::class, 'product_id');
    }

    /** @return HasMany<MeterDimension, $this> */
    public function meterDimensions(): HasMany
    {
        return $this->hasMany(MeterDimension::class, 'product_id');
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
}
