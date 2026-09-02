<?php

declare(strict_types=1);

namespace Meteric\Models;

use Brick\Money\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Meteric\Enums\DiscountKind;
use Meteric\Enums\DiscountState;
use Meteric\Enums\DiscountTarget;
use Meteric\Pricing\DiscountSpec;
use Meteric\Support\Models;

/**
 * A standing reduction on a subscription item, spent one billed period at a
 * time. The accruer raises a negative `discount` charge in the item's own line
 * group for each period it covers, so the invoice shows it under the thing it
 * reduces and the tax on that period falls with it.
 *
 * `terms_total` null runs for the life of the item.
 *
 * @property string $id
 * @property string $subscription_id
 * @property ?string $item_id
 * @property DiscountKind $kind
 * @property ?string $percent
 * @property ?int $amount_minor
 * @property ?string $currency
 * @property DiscountTarget $target
 * @property string $label
 * @property ?int $terms_total
 * @property int $terms_used
 * @property DiscountState $state
 * @property ?array $metadata
 */
class Discount extends MetericModel
{
    protected string $baseTable = 'discounts';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'kind' => DiscountKind::class,
            'target' => DiscountTarget::class,
            'state' => DiscountState::class,
            'amount_minor' => 'integer',
            'terms_total' => 'integer',
            'terms_used' => 'integer',
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<Subscription, $this> */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Models::for(Subscription::class), 'subscription_id');
    }

    /** @return BelongsTo<SubscriptionItem, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Models::for(SubscriptionItem::class), 'item_id');
    }

    public function spec(): DiscountSpec
    {
        return new DiscountSpec(
            kind: $this->kind,
            label: $this->label,
            percent: $this->percent,
            amountMinor: $this->amount_minor,
            currency: $this->currency,
            target: $this->target,
            terms: $this->terms_total,
            metadata: $this->metadata ?? [],
        );
    }

    /** What this takes off `$base`, as a positive magnitude. */
    public function reduce(Money $base): Money
    {
        return $this->spec()->reduce($base);
    }

    /** Terms left to spend. Null `terms_total` never runs out. */
    public function hasTermsLeft(): bool
    {
        return $this->state === DiscountState::Active
            && ($this->terms_total === null || $this->terms_used < $this->terms_total);
    }

    /** How many terms are still to come, null when there is no limit. */
    public function termsLeft(): ?int
    {
        return $this->terms_total === null ? null : max(0, $this->terms_total - $this->terms_used);
    }

    /** Spend one billed period. The last one exhausts it. */
    public function consume(): void
    {
        $used = $this->terms_used + 1;

        $this->forceFill([
            'terms_used' => $used,
            'state' => $this->terms_total !== null && $used >= $this->terms_total
                ? DiscountState::Exhausted
                : $this->state,
        ])->save();
    }

    /** Stop a discount before its terms are spent. Terminal. */
    public function cancel(): void
    {
        if ($this->state === DiscountState::Active) {
            $this->forceFill(['state' => DiscountState::Canceled])->save();
        }
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('state', DiscountState::Active->value);
    }

    public function scopeForTarget(Builder $query, DiscountTarget $target): Builder
    {
        return $query->where('target', $target->value);
    }
}
