<?php

declare(strict_types=1);

namespace Meteric\Subscriptions;

use Brick\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Meteric\Anchoring\BillingPlan;
use Meteric\Anchoring\PlannedPeriod;
use Meteric\Charges\ChargeAccruer;
use Meteric\Contracts\Clock;
use Meteric\Enums\ChargeState;
use Meteric\Enums\DowngradePolicy;
use Meteric\Enums\InvoiceState;
use Meteric\Enums\ItemState;
use Meteric\Enums\LineKind;
use Meteric\Enums\SubscriptionState;
use Meteric\Events\InvoiceOverdue;
use Meteric\Events\SubscriptionCanceled;
use Meteric\Events\SubscriptionPastDue;
use Meteric\Events\SubscriptionPaused;
use Meteric\Events\SubscriptionRenewed;
use Meteric\Events\SubscriptionResumed;
use Meteric\Models\Charge;
use Meteric\Models\Invoice;
use Meteric\Models\Price;
use Meteric\Models\Subscription;
use Meteric\Models\SubscriptionItem;
use Meteric\Proration\Prorator;
use Meteric\Support\Period;

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

        // Paused/canceled subscriptions accrue nothing. past_due still bills
        // (contracts keep invoicing and get dunned).
        if (! $sub->state->isBillable()) {
            return $created;
        }

        $created = DB::transaction(function () use ($sub, $at, &$created): array {
            foreach ($sub->items()->where('state', ItemState::Active->value)->get() as $item) {
                $item->setRelation('subscription', $sub);
                $created = array_merge($created, $this->renewItem($item, $at));
            }

            $sub->forceFill(['current_period' => $this->earliestPeriod($sub)])->save();

            return $created;
        });

        if ($created !== []) {
            SubscriptionRenewed::dispatch($sub, $created);
        }

        return $created;
    }

    /** Suspend billing: state → paused. While paused, renew() skips this subscription. */
    public function pause(Subscription $sub): Subscription
    {
        $sub->forceFill(['state' => SubscriptionState::Paused])->save();
        SubscriptionPaused::dispatch($sub);

        return $sub;
    }

    /**
     * Resume billing: state → active, with each recurring item starting a fresh
     * cycle from $at and billed now. The paused gap is forgiven (no active
     * service while paused means no charge for it), and renewals continue from
     * the new cycle.
     */
    public function resume(Subscription $sub, ?CarbonImmutable $at = null): Subscription
    {
        $at ??= $this->clock->now();

        DB::transaction(function () use ($sub, $at): void {
            $sub->forceFill(['state' => SubscriptionState::Active])->save();

            foreach ($sub->items()->where('state', ItemState::Active->value)->get() as $item) {
                $item->setRelation('subscription', $sub);
                if (! $item->price->isRecurring()) {
                    continue;
                }
                $period = $item->price->recurrence()->period($at);
                $plan = new BillingPlan([new PlannedPeriod($period, LineKind::Recurring)], $period);
                $this->accruer->accrue($item, $plan);
            }

            $sub->forceFill(['current_period' => $this->earliestPeriod($sub)])->save();
        });

        SubscriptionResumed::dispatch($sub->refresh());

        return $sub;
    }

    /**
     * Mark issued invoices past their due date (unpaid) as overdue: flips covered
     * subscriptions to past_due and fires InvoiceOverdue + SubscriptionPastDue.
     * Schedule meteric:mark-overdue to run this. Returns the invoice count.
     */
    public function markOverdue(?CarbonImmutable $at = null): int
    {
        $at ??= $this->clock->now();
        $count = 0;

        Invoice::query()
            ->whereIn('state', [InvoiceState::Open->value, InvoiceState::PartiallyPaid->value])
            ->whereNotNull('due_at')
            ->where('due_at', '<', $at)
            ->each(function (Invoice $invoice) use (&$count): void {
                foreach ($invoice->subscriptions() as $sub) {
                    if (in_array($sub->state, [SubscriptionState::Active, SubscriptionState::Trialing], true)) {
                        $sub->forceFill(['state' => SubscriptionState::PastDue])->save();
                    }
                    SubscriptionPastDue::dispatch($sub, $invoice);
                }
                InvoiceOverdue::dispatch($invoice);
                $count++;
            });

        return $count;
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

        $sub = DB::transaction(function () use ($sub, $when): Subscription {
            $sub->items()->update(['state' => ItemState::Canceled->value, 'ends_at' => $when]);
            $sub->forceFill(['state' => SubscriptionState::Canceled, 'canceled_at' => $when])->save();

            return $sub->refresh();
        });

        SubscriptionCanceled::dispatch($sub);

        return $sub;
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
            'title' => $item->lineTitle(),
            'group' => $item->group,
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
