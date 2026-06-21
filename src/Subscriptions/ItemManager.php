<?php

declare(strict_types=1);

namespace Meteric\Subscriptions;

use Brick\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Meteric\Contracts\Clock;
use Meteric\Enums\ChargeState;
use Meteric\Enums\ItemState;
use Meteric\Enums\LineKind;
use Meteric\Models\Addon;
use Meteric\Models\Charge;
use Meteric\Models\ItemOption;
use Meteric\Models\Price;
use Meteric\Models\SubscriptionItem;
use Meteric\Proration\Prorator;

/**
 * Mid-cycle item mutations — addons, configurable options, quantity. Each change
 * is prorated over the item's remaining period and produces its own itemized
 * charge (or credit), so the invoice breakdown stays line-by-line.
 */
final class ItemManager
{
    public function __construct(
        private Clock $clock,
        private Prorator $prorator,
    ) {}

    /** Book an addon. If it belongs to a group, the active one in that group is swapped out (credited). */
    public function addAddon(SubscriptionItem $item, Price $price, ?string $group = null, float $qty = 1, ?CarbonImmutable $at = null): Addon
    {
        $at ??= $this->clock->now();

        return DB::transaction(function () use ($item, $price, $group, $qty, $at): Addon {
            if ($group !== null) {
                foreach ($item->addons()->where('group_key', $group)->where('state', ItemState::Active->value)->get() as $old) {
                    $this->removeAddon($old, $at);
                }
            }

            $addon = Addon::create([
                'item_id' => $item->id,
                'product_id' => $price->product_id,
                'price_id' => $price->id,
                'group_key' => $group,
                'quantity' => $qty,
                'state' => ItemState::Active,
            ]);

            $this->charge($item, 'addon', $addon->id, LineKind::Addon,
                $this->prorate($item, $price->amountFor($qty), $at),
                $price->product->name ?? 'Addon');

            return $addon;
        });
    }

    /** Remove an addon mid-cycle with a prorated credit for the unused portion. */
    public function removeAddon(Addon $addon, ?CarbonImmutable $at = null): void
    {
        $at ??= $this->clock->now();
        $item = $addon->item;
        $full = $addon->price->amountFor((float) $addon->quantity);

        $this->charge($item, 'addon', $addon->id, LineKind::Credit,
            $this->prorate($item, $full, $at)->negated(),
            'Removed '.($addon->price->product->name ?? 'addon'));

        $addon->forceFill(['state' => ItemState::Canceled])->save();
    }

    /** Set a configurable option (e.g. gameserver slots). Prorates the price delta. */
    public function setOption(SubscriptionItem $item, string $key, string $value, string $type, ?Price $price = null, float $qty = 1, ?CarbonImmutable $at = null): ItemOption
    {
        $at ??= $this->clock->now();

        return DB::transaction(function () use ($item, $key, $value, $type, $price, $qty, $at): ItemOption {
            $existing = $item->options()->where('key', $key)->first();
            $oldQty = (float) ($existing->quantity ?? 0);

            $option = ItemOption::updateOrCreate(
                ['item_id' => $item->id, 'key' => $key],
                ['type' => $type, 'value' => $value, 'price_id' => $price?->id, 'quantity' => $qty],
            );

            if ($price !== null) {
                $deltaQty = $qty - $oldQty;
                if ($deltaQty != 0.0) {
                    $deltaFull = $price->amountFor(abs($deltaQty));
                    $prorated = $this->prorate($item, $deltaFull, $at);
                    $amount = $deltaQty > 0 ? $prorated : $prorated->negated();
                    $kind = $deltaQty > 0 ? LineKind::Option : LineKind::Credit;
                    $this->charge($item, 'item_option', $option->id, $kind, $amount, ucfirst($key));
                }
            }

            return $option;
        });
    }

    /** Change the base quantity of an item, prorating the difference. */
    public function setQuantity(SubscriptionItem $item, float $newQty, ?CarbonImmutable $at = null): SubscriptionItem
    {
        $at ??= $this->clock->now();
        $delta = $newQty - (float) $item->quantity;

        return DB::transaction(function () use ($item, $newQty, $delta, $at): SubscriptionItem {
            if ($delta != 0.0) {
                $deltaFull = $item->price->amountFor(abs($delta));
                $prorated = $this->prorate($item, $deltaFull, $at);
                $amount = $delta > 0 ? $prorated : $prorated->negated();
                $kind = $delta > 0 ? LineKind::Prorated : LineKind::Credit;
                $this->charge($item, 'subscription_item', $item->id, $kind, $amount, 'Quantity change');
            }

            $item->forceFill(['quantity' => $newQty])->save();

            return $item->refresh();
        });
    }

    private function prorate(SubscriptionItem $item, Money $full, CarbonImmutable $at): Money
    {
        $period = $item->current_period;
        if ($period === null) {
            return $full;
        }

        return $this->prorator->for($period, $at, $full)->amount();
    }

    private function charge(SubscriptionItem $item, string $originType, string $originId, LineKind $kind, Money $amount, string $desc): void
    {
        $sub = $item->subscription;

        Charge::create([
            'account_id' => $sub->account_id,
            'subscription_id' => $sub->id,
            'origin_type' => $originType,
            'origin_id' => $originId,
            'kind' => $kind,
            'billing_mode' => $item->billingMode(),
            'state' => ChargeState::Pending,
            'title' => $item->lineTitle(),
            'group' => $item->group,
            'description' => $desc,
            'quantity' => 1,
            'unit_minor' => $amount->getMinorAmount()->toInt(),
            'amount_minor' => $amount->getMinorAmount()->toInt(),
            'currency' => $sub->currency,
            'covers' => $item->current_period,
            'idempotency_key' => 'item_'.Str::uuid()->toString(),
        ]);
    }
}
