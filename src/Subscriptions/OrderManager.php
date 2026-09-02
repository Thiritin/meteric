<?php

declare(strict_types=1);

namespace Meteric\Subscriptions;

use Brick\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Meteric\Anchoring\PeriodPlanner;
use Meteric\Contracts\Clock;
use Meteric\Enums\ItemState;
use Meteric\Enums\LineKind;
use Meteric\Enums\OrderState;
use Meteric\Enums\SubscriptionState;
use Meteric\Events\OrderCanceled;
use Meteric\Events\OrderConverted;
use Meteric\Events\OrderExpired;
use Meteric\Events\OrderPaid;
use Meteric\Events\SubscriptionStarted;
use Meteric\Exceptions\LineNotMaterializable;
use Meteric\Meteric;
use Meteric\Models\Addon;
use Meteric\Models\Charge;
use Meteric\Models\Invoice;
use Meteric\Models\ItemOption;
use Meteric\Models\Order;
use Meteric\Models\Payment;
use Meteric\Models\Price;
use Meteric\Models\Subscription;
use Meteric\Models\SubscriptionItem;
use Meteric\Support\Models;
use Meteric\Support\Period;

/**
 * Settles a persisted order. Payment is the only thing that materializes a real
 * Subscription (+ items/addons/options) and a Paid invoice; the charges it
 * accrues use the FROZEN amounts captured at open time, so a catalog price
 * change mid-flight never moves the order's figures. Conversion is idempotent
 * (row lock + state guard), so a double payment yields exactly one subscription.
 */
final class OrderManager
{
    public function __construct(private Clock $clock, private PeriodPlanner $planner) {}

    /**
     * Pay an order in full (gross total) and convert it. Paying an order that has
     * already converted is a no-op (returns it unchanged), so a retried payment
     * never double-bills. A canceled or expired order is rejected.
     */
    public function pay(Order $order, Money $amount, ?string $ref = null, ?CarbonImmutable $at = null): Order
    {
        if ($order->isConverted()) {
            return $order;
        }
        if (! $order->isPending()) {
            throw new \LogicException("Order {$order->id} is {$order->state->value} and cannot be paid.");
        }

        $expected = $amount->getMinorAmount()->toInt();
        if ($expected !== $order->total_minor || $amount->getCurrency()->getCurrencyCode() !== $order->currency) {
            throw new \InvalidArgumentException('Payment must equal the order gross total in its currency.');
        }

        return $this->convert($order, $amount, $ref, $at);
    }

    /** Convert a zero-total order with no payment (e.g. a fully trialed signup). */
    public function confirm(Order $order, ?CarbonImmutable $at = null): Order
    {
        if (! $order->isPending()) {
            throw new \LogicException('Only a pending order can be confirmed.');
        }

        return $this->convert($order, null, null, $at);
    }

    /** Cancel a pending order. No-op once terminal. */
    public function cancel(Order $order, ?CarbonImmutable $at = null): Order
    {
        if ($order->state->isTerminal()) {
            return $order;
        }

        $order->forceFill([
            'state' => OrderState::Canceled,
            'canceled_at' => $at ?? $this->clock->now(),
        ])->save();

        OrderCanceled::dispatch($order);

        return $order;
    }

    /**
     * Materialize one frozen line (the entry whose `group` matches) onto a
     * subscription the caller owns, for hosts that want one subscription per
     * line rather than the single one convert() builds. Creates the item, its
     * Addon and ItemOption rows, and accrues the frozen charges (first period,
     * setup, addons, options) for that line only. Nothing else about the order
     * moves: the caller records its own conversion. Idempotent on the group:
     * a line already on the subscription is returned unchanged.
     *
     * @throws LineNotMaterializable when the base price is relative, or carries an
     *                               allowance, block size or cap; an item renews
     *                               through amountFor() and would drift from the
     *                               frozen figure. Addons inside the line are fine:
     *                               they renew as Addon rows through the full engine.
     */
    public function materializeLine(Order $order, string $group, Subscription $sub, ?Model $resource = null, ?CarbonImmutable $at = null): SubscriptionItem
    {
        if ($order->state->isTerminal() && ! $order->isConverted()) {
            throw new \LogicException("Order {$order->id} is {$order->state->value} and cannot be materialized.");
        }
        if ($sub->currency !== $order->currency) {
            throw new \InvalidArgumentException("Subscription currency {$sub->currency} does not match order currency {$order->currency}.");
        }

        $content = null;
        foreach ($order->contents as $entry) {
            if (($entry['group'] ?? null) === $group) {
                $content = $entry;
                break;
            }
        }
        if ($content === null) {
            throw new \InvalidArgumentException("Order {$order->id} has no line in group {$group}.");
        }

        $price = Models::query(Price::class)->find($content['price_id']);
        if ($price === null) {
            throw new \RuntimeException("Order price {$content['price_id']} no longer resolves.");
        }
        $this->guardRenewable($price, $content);

        return DB::transaction(function () use ($order, $group, $sub, $resource, $at, $content): SubscriptionItem {
            $existing = $sub->items()->where('group', $group)->first();
            if ($existing !== null) {
                return $existing;
            }

            $when = $at ?? $this->clock->now();
            $signup = $sub->trial_end ?? $when;

            $item = $this->materializeItem($sub, $order, $content, $signup, $resource);

            $end = $item->current_period->end;
            $period = $sub->current_period;
            $sub->forceFill(['current_period' => $period === null
                ? new Period($when, $end)
                : new Period($period->start, min($period->end, $end)),
            ])->save();

            return $item;
        });
    }

    /** Expire every pending order past its expiry. Returns the count. Idempotent. */
    public function expireDue(?CarbonImmutable $at = null): int
    {
        $at ??= $this->clock->now();
        $count = 0;

        Models::query(Order::class)
            ->where('state', OrderState::Pending->value)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $at)
            ->cursor()
            ->each(function (Order $order) use ($at, &$count): void {
                $order->forceFill(['state' => OrderState::Expired, 'canceled_at' => $at])->save();
                OrderExpired::dispatch($order);
                $count++;
            });

        return $count;
    }

    /**
     * Materialize the order: one Subscription, its items/addons/options, and the
     * frozen pending charges, then invoice and (optionally) record payment. The
     * whole thing runs under a row lock with a state guard so it is idempotent.
     */
    private function convert(Order $order, ?Money $amount, ?string $ref, ?CarbonImmutable $at): Order
    {
        $paying = $amount !== null && $amount->isPositive();

        $result = DB::transaction(function () use ($order, $amount, $ref, $at, $paying): array {
            $locked = Models::query(Order::class)->lockForUpdate()->findOrFail($order->id);

            // Idempotency guard: already converted -> return unchanged.
            if (! $locked->isPending() || $locked->subscription_id !== null) {
                return [$locked, null, null, null];
            }

            $when = $at ?? $this->clock->now();
            $trialEnd = $locked->trial_days > 0 ? $when->addDays($locked->trial_days) : null;
            $signup = $trialEnd ?? $when;

            $sub = Models::query(Subscription::class)->create([
                'account_id' => $locked->account_id,
                'customer_type' => $locked->customer_type,
                'customer_id' => $locked->customer_id,
                'currency' => $locked->currency,
                'state' => $trialEnd ? SubscriptionState::Trialing : SubscriptionState::Active,
                'anchor_mode' => $locked->anchor_mode,
                'anchor_day' => $locked->anchor_day,
                'first_period' => $locked->first_period,
                'trial_end' => $trialEnd,
            ]);
            $sub->setRelation('account', $locked->account);

            $ends = [];
            foreach ($locked->contents as $content) {
                $ends[] = $this->materializeItem($sub, $locked, $content, $signup)->current_period->end;
            }

            if ($ends !== []) {
                $sub->forceFill(['current_period' => new Period($when, min($ends))])->save();
            }

            $invoice = app(Meteric::class)->invoicePending($sub->account, $locked->currency);

            $payment = null;
            if ($paying && $invoice !== null) {
                $payment = app(Meteric::class)->recordPayment($invoice, $amount, $ref);
            }

            $locked->forceFill([
                'state' => OrderState::Converted,
                'subscription_id' => $sub->id,
                'invoice_id' => $invoice?->id,
                'paid_at' => $paying ? $when : null,
                'converted_at' => $when,
            ])->save();

            return [$locked, $sub, $invoice, $payment];
        });

        /** @var array{0:Order,1:?Subscription,2:?Invoice,3:?Payment} $result */
        [$converted, $sub, $invoice, $payment] = $result;

        if ($sub instanceof Subscription) {
            OrderPaid::dispatch($converted, $invoice, $payment);
            SubscriptionStarted::dispatch($converted, $sub, $invoice);
            OrderConverted::dispatch($converted, $sub);
        }

        return $converted;
    }

    /**
     * Close a pending order as converted without materializing anything: for a
     * basket a person reviewed and applied by other means, a plan change put
     * through changePlan() on an existing subscription, say. Stamps converted_at,
     * the subscription when given, merges $meta into the order's metadata, and
     * fires OrderConverted. Creates and invoices nothing.
     *
     * @param  array<string,mixed>  $meta
     */
    public function complete(Order $order, ?Subscription $subscription = null, array $meta = [], ?CarbonImmutable $at = null): Order
    {
        if (! $order->isPending()) {
            throw new \LogicException("Order {$order->id} is {$order->state->value}; only a pending order can be completed.");
        }

        $order->forceFill([
            'state' => OrderState::Converted,
            'subscription_id' => $subscription?->id,
            'converted_at' => $at ?? $this->clock->now(),
            'metadata' => array_merge($order->metadata ?? [], $meta),
        ])->save();

        OrderConverted::dispatch($order, $subscription);

        return $order;
    }

    /**
     * Build one subscription item plus its addons and options from a frozen cart
     * entry, accruing a pending Charge per piece using the captured amounts. The
     * period is recomputed at conversion time so the service window is fresh, but
     * the money is the frozen money. A resource passed in wins over the frozen one.
     *
     * @param  array<string,mixed>  $content
     */
    private function materializeItem(Subscription $sub, Order $order, array $content, CarbonImmutable $signup, ?Model $resource = null): SubscriptionItem
    {
        $price = Models::query(Price::class)->find($content['price_id']);
        if ($price === null) {
            throw new \RuntimeException("Order price {$content['price_id']} no longer resolves.");
        }

        $covers = $this->itemPeriod($price, $order, $signup);

        $item = Models::query(SubscriptionItem::class)->create([
            'subscription_id' => $sub->id,
            'product_id' => $content['product_id'],
            'price_id' => $price->id,
            'resource_type' => $resource?->getMorphClass() ?? $content['resource_type'] ?? null,
            'resource_id' => $resource !== null ? (string) $resource->getKey() : ($content['resource_id'] ?? null),
            'label' => $content['label'] ?? null,
            'group' => $content['group'] ?? null,
            'quantity' => $content['quantity'],
            'state' => ItemState::Active,
            'activated_at' => $signup,
            'current_period' => $covers,
        ]);
        $item->setRelation('subscription', $sub);
        $item->setRelation('price', $price);

        $kind = LineKind::from($content['kind']);
        $this->charge($item, 'subscription_item', $item->id, $kind, (int) $content['amount_minor'],
            $item->lineTitle(), $covers, (float) $content['quantity']);

        // Orders frozen before setup_minor existed carry no key and owe nothing here.
        if ((int) ($content['setup_minor'] ?? 0) > 0) {
            $this->charge($item, 'subscription_item', $item->id, LineKind::Setup, (int) $content['setup_minor'],
                'Setup', null, 1);
        }

        foreach ($content['addons'] ?? [] as $addon) {
            $addonModel = Models::query(Addon::class)->create([
                'item_id' => $item->id,
                'product_id' => $addon['product_id'],
                'price_id' => $addon['price_id'],
                'group_key' => $addon['group_key'] ?? null,
                'quantity' => $addon['quantity'],
                'state' => ItemState::Active,
            ]);

            $this->charge($item, 'addon', $addonModel->id, LineKind::Addon, (int) $addon['amount_minor'],
                $item->lineTitle(), $covers, (float) $addon['quantity']);
        }

        foreach ($content['options'] ?? [] as $opt) {
            $option = Models::query(ItemOption::class)->create([
                'item_id' => $item->id,
                'key' => $opt['key'],
                'type' => $opt['type'],
                'value' => $opt['value'],
                'label' => $opt['label'] ?? null,
                'price_id' => $opt['price_id'] ?? null,
                'quantity' => $opt['quantity'],
                'min_qty' => $opt['min_qty'] ?? null,
                'max_qty' => $opt['max_qty'] ?? null,
            ]);

            $this->charge($item, 'item_option', $option->id, LineKind::Option, (int) $opt['amount_minor'],
                ucfirst((string) $opt['key']), $covers, (float) $opt['quantity']);

            if ((int) ($opt['setup_minor'] ?? 0) > 0) {
                $this->charge($item, 'item_option', $option->id, LineKind::Setup, (int) $opt['setup_minor'],
                    ucfirst((string) $opt['key']).' setup', null, 1);
            }
        }

        return $item;
    }

    /**
     * A base line renews through SubscriptionItem::periodAmount(), which is
     * amountFor(qty) and nothing more. A price that only prices correctly
     * through amountOfBase() or amountForQuantity() would renew at the wrong
     * figure, so it is refused rather than approximated.
     *
     * @param  array<string,mixed>  $content
     */
    private function guardRenewable(Price $price, array $content): void
    {
        $label = $content['label'] ?? $content['group'] ?? $price->id;

        if ($price->isRelative()) {
            throw new LineNotMaterializable("Line {$label} has a relative base price; book it as an addon of the line it is a percentage of.");
        }
        if ($price->included_qty > 0 || $price->block_size !== null || $price->cap_minor !== null) {
            throw new LineNotMaterializable("Line {$label} has an allowance, block size or cap on its base price; an item cannot renew it exactly.");
        }
    }

    /** Recompute the first service window at conversion time (period only, not money). */
    private function itemPeriod(Price $price, Order $order, CarbonImmutable $signup): Period
    {
        if (! $price->isRecurring()) {
            return new Period($signup, $signup->addSecond());
        }

        return $this->planner
            ->plan($signup, $price->recurrence(), $order->anchor_mode, $order->anchor_day, $order->first_period)
            ->ongoing;
    }

    /** Create a pending Charge using a frozen minor amount (zero amounts are skipped). */
    private function charge(
        SubscriptionItem $item,
        string $originType,
        string $originId,
        LineKind $kind,
        int $amountMinor,
        string $description,
        ?Period $covers,
        float $quantity,
    ): void {
        if ($amountMinor === 0) {
            return;
        }

        Charge::pendingForItem($item, [
            'origin_type' => $originType,
            'origin_id' => $originId,
            'kind' => $kind,
            'description' => $description,
            'quantity' => $quantity,
            'unit_minor' => $amountMinor,
            'amount_minor' => $amountMinor,
            'covers' => $covers,
            'idempotency_key' => 'order_'.Str::uuid()->toString(),
        ]);
    }
}
