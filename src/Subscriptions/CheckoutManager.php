<?php

declare(strict_types=1);

namespace Meteric\Subscriptions;

use Brick\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Meteric\Anchoring\PeriodPlanner;
use Meteric\Contracts\Clock;
use Meteric\Enums\ChargeState;
use Meteric\Enums\CheckoutState;
use Meteric\Enums\ItemState;
use Meteric\Enums\LineKind;
use Meteric\Enums\SubscriptionState;
use Meteric\Events\CheckoutCanceled;
use Meteric\Events\CheckoutExpired;
use Meteric\Events\CheckoutPaid;
use Meteric\Events\SubscriptionStarted;
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
use Meteric\Support\Period;

/**
 * Settles a persisted order. Payment is the only thing that materializes a real
 * Subscription (+ items/addons/options) and a Paid invoice; the charges it
 * accrues use the FROZEN amounts captured at open time, so a catalog price
 * change mid-flight never moves the order's figures. Conversion is idempotent
 * (row lock + state guard), so a double payment yields exactly one subscription.
 */
final class CheckoutManager
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
            'state' => CheckoutState::Canceled,
            'canceled_at' => $at ?? $this->clock->now(),
        ])->save();

        CheckoutCanceled::dispatch($order);

        return $order;
    }

    /** Expire every pending order past its expiry. Returns the count. Idempotent. */
    public function expireDue(?CarbonImmutable $at = null): int
    {
        $at ??= $this->clock->now();
        $count = 0;

        Order::query()
            ->where('state', CheckoutState::Pending->value)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $at)
            ->cursor()
            ->each(function (Order $order) use ($at, &$count): void {
                $order->forceFill(['state' => CheckoutState::Expired, 'canceled_at' => $at])->save();
                CheckoutExpired::dispatch($order);
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
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);

            // Idempotency guard: already converted -> return unchanged.
            if (! $locked->isPending() || $locked->subscription_id !== null) {
                return [$locked, null, null, null];
            }

            $when = $at ?? $this->clock->now();
            $trialEnd = $locked->trial_days > 0 ? $when->addDays($locked->trial_days) : null;
            $signup = $trialEnd ?? $when;

            $sub = Subscription::create([
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
                $ends[] = $this->materializeItem($sub, $locked, $content, $signup);
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
                'state' => CheckoutState::Converted,
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
            CheckoutPaid::dispatch($converted, $invoice, $payment);
            SubscriptionStarted::dispatch($converted, $sub, $invoice);
        }

        return $converted;
    }

    /**
     * Build one subscription item plus its addons and options from a frozen cart
     * entry, accruing a pending Charge per piece using the captured amounts. The
     * period is recomputed at conversion time so the service window is fresh, but
     * the money is the frozen money. Returns the item's period end.
     *
     * @param  array<string,mixed>  $content
     */
    private function materializeItem(Subscription $sub, Order $order, array $content, CarbonImmutable $signup): CarbonImmutable
    {
        $price = Price::find($content['price_id']);
        if ($price === null) {
            throw new \RuntimeException("Order price {$content['price_id']} no longer resolves.");
        }

        $covers = $this->itemPeriod($price, $order, $signup);

        $item = SubscriptionItem::create([
            'subscription_id' => $sub->id,
            'product_id' => $content['product_id'],
            'price_id' => $price->id,
            'resource_type' => $content['resource_type'] ?? null,
            'resource_id' => $content['resource_id'] ?? null,
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
        $this->charge($sub, $item, 'subscription_item', $item->id, $kind, (int) $content['amount_minor'],
            $item->lineTitle(), $covers, (float) $content['quantity']);

        foreach ($content['addons'] ?? [] as $addon) {
            $addonModel = Addon::create([
                'item_id' => $item->id,
                'product_id' => $addon['product_id'],
                'price_id' => $addon['price_id'],
                'group_key' => $addon['group_key'] ?? null,
                'quantity' => $addon['quantity'],
                'state' => ItemState::Active,
            ]);

            $this->charge($sub, $item, 'addon', $addonModel->id, LineKind::Addon, (int) $addon['amount_minor'],
                $item->lineTitle(), $covers, (float) $addon['quantity']);
        }

        foreach ($content['options'] ?? [] as $opt) {
            $option = ItemOption::create([
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

            $this->charge($sub, $item, 'item_option', $option->id, LineKind::Option, (int) $opt['amount_minor'],
                ucfirst((string) $opt['key']), $covers, (float) $opt['quantity']);

            if ((int) ($opt['setup_minor'] ?? 0) > 0) {
                $this->charge($sub, $item, 'item_option', $option->id, LineKind::Setup, (int) $opt['setup_minor'],
                    ucfirst((string) $opt['key']).' setup', null, 1);
            }
        }

        return $covers->end;
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
        Subscription $sub,
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
            'description' => $description,
            'quantity' => $quantity,
            'unit_minor' => $amountMinor,
            'amount_minor' => $amountMinor,
            'currency' => $sub->currency,
            'covers' => $covers,
            'idempotency_key' => 'order_'.Str::uuid()->toString(),
        ]);
    }
}
