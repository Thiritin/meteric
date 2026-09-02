<?php

declare(strict_types=1);

namespace Meteric\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Meteric\Casts\ProductConfigCast;
use Meteric\Enums\DowngradePolicy;
use Meteric\Enums\Interval;
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

    /** @return HasMany<ProductAddon, $this> */
    public function addons(): HasMany
    {
        return $this->hasMany(Models::for(ProductAddon::class), 'product_id')->orderBy('sort');
    }

    /**
     * The bookable addons priced on one of this product's terms, as render-ready
     * data for a checkout form. Addons with no price for that term are left out.
     *
     * @return list<array<string,mixed>>
     */
    public function addonCatalog(Price $term, float $qty = 1): array
    {
        if ($term->product_id !== $this->id) {
            throw new \InvalidArgumentException("Price {$term->id} does not belong to product {$this->slug}.");
        }

        return $this->addons()->with('addon')->get()
            ->map(fn (ProductAddon $a): ?array => $a->toDisplay($term, $qty))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * The current price for a currency and purpose. Pass an interval to pick
     * one term when a product carries several (monthly and yearly, say); without
     * it the newest current price wins whatever its term.
     */
    public function priceFor(string $currency, PricePurpose $purpose = PricePurpose::Recurring, ?Interval $interval = null, ?int $intervalCount = null): ?Price
    {
        return $this->currentPrices($currency, $purpose)
            ->when($interval !== null, fn (Builder $q) => $q
                ->where('interval', $interval?->value)
                ->where('interval_count', $intervalCount ?? 1))
            ->latest('valid_from')
            ->first();
    }

    /**
     * The terms this product is sold on in a currency: one current recurring
     * price per interval, shortest term first.
     *
     * @return Collection<int, Price>
     */
    public function terms(string $currency, PricePurpose $purpose = PricePurpose::Recurring): Collection
    {
        $epoch = CarbonImmutable::create(2000, 1, 1, 0, 0, 0, 'UTC');

        return $this->currentPrices($currency, $purpose)
            ->whereNotNull('interval')
            ->whereNotNull('interval_count')
            ->orderByDesc('valid_from')
            ->get()
            ->unique(fn (Price $p): string => $p->interval?->value.':'.$p->interval_count)
            ->sortBy(fn (Price $p): int => $p->recurrence()->nextEnd($epoch)->getTimestamp())
            ->values();
    }

    /**
     * The terms as render-ready data for a checkout form, each priced at $qty.
     *
     * @return list<array<string,mixed>>
     */
    public function termCatalog(string $currency, float $qty = 1, PricePurpose $purpose = PricePurpose::Recurring): array
    {
        return $this->terms($currency, $purpose)
            ->map(fn (Price $p): array => $p->toDisplay($qty))
            ->all();
    }

    /** @return HasMany<Price, $this> */
    private function currentPrices(string $currency, PricePurpose $purpose): HasMany
    {
        return $this->prices()
            ->whereNull('valid_to')
            ->where('currency', $currency)
            ->where('purpose', $purpose->value);
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
