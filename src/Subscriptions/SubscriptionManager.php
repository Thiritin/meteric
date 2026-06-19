<?php

declare(strict_types=1);

namespace Billify\Subscriptions;

use Billify\Anchoring\BillingPlan;
use Billify\Anchoring\PlannedPeriod;
use Billify\Charges\ChargeAccruer;
use Billify\Contracts\Clock;
use Billify\Enums\ChargeState;
use Billify\Enums\DowngradePolicy;
use Billify\Enums\ItemState;
use Billify\Enums\LineKind;
use Billify\Enums\SubscriptionState;
use Billify\Models\Charge;
use Billify\Models\Price;
use Billify\Models\Subscription;
use Billify\Models\SubscriptionItem;
use Billify\Proration\Prorator;
use Billify\Support\Period;
use Brick\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Lifecycle operations on existing subscriptions: renew, change plan, cancel. */
final class SubscriptionManager
{
    public function __construct(
        private Clock $clock,
        private Prorator $prorator,
        private ChargeAccruer $accruer,
    ) {}

    /**
     * Accrue the next cycle for every due item (catches up missed periods).
     * Idempotent — the billing-period guard prevents double billing.
     *
     * @return list<Charge>
     */
    public function renew(Subscription $sub, ?CarbonImmutable $at = null): array
    {
        $at ??= $this->clock->now();
        $created = [];

        return DB::transaction(function () use ($sub, $at, &$created): array {
            foreach ($sub->items()->where('state', ItemState::Active->value)->get() as $item) {
                $item->setRelation('subscription', $sub);
                $created = array_merge($created, $this->renewItem($item, $at));
            }

            $sub->forceFill(['current_period' => $this->earliestPeriod($sub)])->save();

            return $created;
        });
    }

    /** @return list<Charge> */
    private function renewItem(SubscriptionItem $item, CarbonImmutable $at): array
    {
        $created = [];
        $price = $item->price;

        if (! $price->isRecurring() || $item->current_period === null) {
            return $created;
        }

        // Roll forward through any elapsed periods.
        while ($item->current_period->end <= $at) {
            $this->applyPendingChange($item);
            $next = $item->price->recurrence()->period($item->current_period->end);
            $plan = new BillingPlan([new PlannedPeriod($next, LineKind::Recurring)], $next);
            $created = array_merge($created, $this->accruer->accrue($item, $plan));
            $item->refresh()->setRelation('price', $item->price);
        }

        return $created;
    }

    /** A plan change scheduled for period end is applied at the boundary. */
    private function applyPendingChange(SubscriptionItem $item): void
    {
        $change = $item->pending_change;
        if (! $change || empty($change['price_id'])) {
            return;
        }

        $item->forceFill(['price_id' => $change['price_id'], 'pending_change' => null])->save();
        $item->load('price');
    }

    /**
     * Switch an item's plan. Direction is detected by price:
     *  - Upgrade  → charge the prorated difference now (credit unused old + charge prorated new).
     *  - Downgrade→ apply the downgrade policy: `defer` (swap at next renewal, keep current tier
     *               until then) or `discard` (swap now, unused value forfeited — no credit/refund).
     *
     * Neither downgrade path moves money mid-cycle. $downgrade overrides the product's policy.
     */
    public function changePlan(SubscriptionItem $item, Price $newPrice, ?DowngradePolicy $downgrade = null, ?CarbonImmutable $at = null): SubscriptionItem
    {
        $at ??= $this->clock->now();
        $qty = (float) $item->quantity;
        $oldFull = $item->price->amountFor($qty);
        $newFull = $newPrice->amountFor($qty);

        if ($newFull->isGreaterThan($oldFull)) {
            return $this->upgrade($item, $newPrice, $at);
        }

        $policy = $downgrade ?? $item->product?->downgradePolicy() ?? DowngradePolicy::Defer;

        if ($policy === DowngradePolicy::Defer) {
            $item->forceFill(['pending_change' => ['price_id' => $newPrice->id, 'apply_at' => $item->current_period?->end?->toIso8601String()]])->save();

            return $item;
        }

        // Discard: switch now, no money. Unused value of the higher plan is forfeited.
        $item->forceFill(['price_id' => $newPrice->id, 'product_id' => $newPrice->product_id])->save();

        return $item->refresh();
    }

    private function upgrade(SubscriptionItem $item, Price $newPrice, CarbonImmutable $at): SubscriptionItem
    {
        return DB::transaction(function () use ($item, $newPrice, $at): SubscriptionItem {
            $sub = $item->subscription;
            $period = $item->current_period;
            $qty = (float) $item->quantity;

            if ($period !== null) {
                $unusedOld = $this->prorator->for($period, $at, $item->price->amountFor($qty))->amount();
                $proratedNew = $this->prorator->for($period, $at, $newPrice->amountFor($qty))->amount();

                $this->prorationCharge($item, $sub, LineKind::Credit, $unusedOld->negated(), 'Unused '.($item->price->product->name ?? 'plan'));
                $this->prorationCharge($item, $sub, LineKind::Prorated, $proratedNew, 'Upgrade '.($newPrice->product->name ?? 'plan'));
            }

            $item->forceFill(['price_id' => $newPrice->id, 'product_id' => $newPrice->product_id])->save();

            return $item->refresh();
        });
    }

    /** Cancel `now` (immediate) or at `period_end`. No automatic refund (deferred). */
    public function cancel(Subscription $sub, string $at = 'period_end', ?CarbonImmutable $when = null): Subscription
    {
        $when ??= $this->clock->now();

        if ($at === 'period_end') {
            $sub->forceFill(['cancel_at' => $sub->current_period?->end ?? $when])->save();

            return $sub;
        }

        return DB::transaction(function () use ($sub, $when): Subscription {
            $sub->items()->update(['state' => ItemState::Canceled->value, 'ends_at' => $when]);
            $sub->forceFill(['state' => SubscriptionState::Canceled, 'canceled_at' => $when])->save();

            return $sub->refresh();
        });
    }

    private function prorationCharge(SubscriptionItem $item, Subscription $sub, LineKind $kind, Money $amount, string $desc): void
    {
        Charge::create([
            'account_id' => $sub->account_id,
            'subscription_id' => $sub->id,
            'origin_type' => 'subscription_item',
            'origin_id' => $item->id,
            'kind' => $kind,
            'billing_mode' => $item->billingMode(),
            'state' => ChargeState::Pending,
            'description' => $desc,
            'quantity' => $item->quantity,
            'unit_minor' => $amount->getMinorAmount()->toInt(),
            'amount_minor' => $amount->getMinorAmount()->toInt(),
            'currency' => $sub->currency,
            'covers' => $item->current_period,
            'idempotency_key' => 'prorate_'.Str::uuid()->toString(),
        ]);
    }

    private function earliestPeriod(Subscription $sub): ?Period
    {
        $periods = $sub->items()->where('state', ItemState::Active->value)->get()
            ->map(fn (SubscriptionItem $i) => $i->current_period)->filter();

        if ($periods->isEmpty()) {
            return $sub->current_period;
        }

        return $periods->sortBy(fn ($p) => $p->end->getTimestamp())->first();
    }
}
