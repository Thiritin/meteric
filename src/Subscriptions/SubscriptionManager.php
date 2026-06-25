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
use Meteric\Enums\BillingMode;
use Meteric\Enums\ChargeState;
use Meteric\Enums\DowngradePolicy;
use Meteric\Enums\InvoiceState;
use Meteric\Enums\ItemState;
use Meteric\Enums\LineKind;
use Meteric\Enums\SubscriptionState;
use Meteric\Enums\UpgradePolicy;
use Meteric\Events\InvoiceOverdue;
use Meteric\Events\SubscriptionCanceled;
use Meteric\Events\SubscriptionCancellationScheduled;
use Meteric\Events\SubscriptionPastDue;
use Meteric\Events\SubscriptionPaused;
use Meteric\Events\SubscriptionRenewed;
use Meteric\Events\SubscriptionResumed;
use Meteric\Meteric;
use Meteric\Models\Charge;
use Meteric\Models\Invoice;
use Meteric\Models\InvoiceLine;
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
            ->whereNull('overdue_at')   // fire once per invoice; safe to run every few minutes
            ->each(function (Invoice $invoice) use ($at, &$count): void {
                $invoice->forceFill(['overdue_at' => $at])->save();
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

        $cancelAt = $item->subscription->cancel_at;

        // Roll forward through any elapsed periods.
        while ($item->current_period->end <= $at) {
            // A scheduled cancellation stops billing at its boundary: do not
            // accrue a period that starts on or after cancel_at.
            if ($cancelAt !== null && $item->current_period->end >= $cancelAt) {
                break;
            }
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
     * Switch an item's plan. Direction is detected by price. Each direction takes
     * a policy ($upgrade / $downgrade overrides the product default):
     *
     *  Upgrade   prorate: credit the unused old, charge the prorated new (default).
     *            defer: swap at the next renewal, keep the current plan until then.
     *  Downgrade defer: keep the tier until the period ends, then renew lower.
     *            discard: swap now, unused value forfeited. credit: swap now, credit the
     *            unused old as a pending charge on the next invoice. refund: swap now and
     *            issue a credit note for the unused value (a host listener moves the money).
     *
     * In-arrears (usage/postpaid) items ignore the policies: a change is rate-forward.
     */
    public function changePlan(SubscriptionItem $item, Price $newPrice, ?DowngradePolicy $downgrade = null, ?UpgradePolicy $upgrade = null, ?CarbonImmutable $at = null): SubscriptionItem
    {
        $at ??= $this->clock->now();

        // Postpaid / usage items have no prepaid value to prorate, credit, or
        // refund. A change is rate-forward: swap the price, the rest of the cycle
        // bills at the new rate. Proration policies apply only to prepaid items.
        if ($item->billingMode() === BillingMode::InArrears) {
            $item->forceFill(['price_id' => $newPrice->id, 'product_id' => $newPrice->product_id])->save();

            return $item->refresh();
        }

        $qty = (float) $item->quantity;
        $oldFull = $item->price->amountFor($qty);
        $newFull = $newPrice->amountFor($qty);

        if ($newFull->isGreaterThan($oldFull)) {
            return match ($upgrade ?? UpgradePolicy::Prorate) {
                UpgradePolicy::Defer => $this->deferChange($item, $newPrice),
                UpgradePolicy::Prorate => $this->prorateChange($item, $newPrice, $at),
            };
        }

        return match ($downgrade ?? $item->product?->downgradePolicy() ?? DowngradePolicy::Defer) {
            DowngradePolicy::Defer => $this->deferChange($item, $newPrice),
            DowngradePolicy::Credit => $this->switchNow($item, $newPrice, $at, creditOld: true),
            DowngradePolicy::Refund => $this->refundDowngrade($item, $newPrice, $at),
            DowngradePolicy::Discard => $this->switchNow($item, $newPrice, $at),
        };
    }

    /**
     * Refund downgrade: swap now and issue a credit note for the unused value of
     * the invoice that billed the current period (a refund document; a host
     * listener moves the money). With nothing invoiced yet there is nothing to
     * refund, so the unused value becomes a pending credit on the next invoice.
     */
    private function refundDowngrade(SubscriptionItem $item, Price $newPrice, CarbonImmutable $at): SubscriptionItem
    {
        return DB::transaction(function () use ($item, $newPrice, $at): SubscriptionItem {
            $sub = $item->subscription;
            $period = $item->current_period;

            if ($period !== null) {
                $unused = $this->prorator->for($period, $at, $item->price->amountFor((float) $item->quantity))->amount();

                if ($unused->isPositive()) {
                    $invoice = $this->periodInvoice($item);
                    if ($invoice !== null) {
                        app(Meteric::class)->creditNote($invoice, $unused, 'Downgrade refund: '.($item->price->product->name ?? 'plan'));
                    } else {
                        $this->prorationCharge($item, $sub, LineKind::Credit, $unused->negated(), 'Unused '.($item->price->product->name ?? 'plan'));
                    }
                }
            }

            $item->forceFill(['price_id' => $newPrice->id, 'product_id' => $newPrice->product_id])->save();

            return $item->refresh();
        });
    }

    /** The live (non-void) invoice that billed this item's current period, if any. */
    private function periodInvoice(SubscriptionItem $item): ?Invoice
    {
        if ($item->current_period === null) {
            return null;
        }

        $chargeIds = Charge::query()
            ->where('origin_type', 'subscription_item')
            ->where('origin_id', $item->id)
            ->whereRaw('covers && ?::tstzrange', [$item->current_period->toRange()])
            ->latest('created_at')
            ->pluck('id');

        return Invoice::query()
            ->whereIn('id', InvoiceLine::query()->whereIn('charge_id', $chargeIds)->select('invoice_id'))
            ->where('state', '<>', InvoiceState::Void->value)
            ->latest('created_at')
            ->first();
    }

    /** Queue the swap for the next renewal boundary; no money moves mid-cycle. */
    private function deferChange(SubscriptionItem $item, Price $newPrice): SubscriptionItem
    {
        $item->forceFill(['pending_change' => ['price_id' => $newPrice->id, 'apply_at' => $item->current_period?->end?->toIso8601String()]])->save();

        return $item;
    }

    /** Prorate upgrade: credit the unused old and charge the prorated new. */
    private function prorateChange(SubscriptionItem $item, Price $newPrice, CarbonImmutable $at): SubscriptionItem
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

    /**
     * Swap the plan immediately. Optionally credit the unused old value (downgrade
     * `credit`); plain discard does not. The credit is a pending charge on the next
     * invoice.
     */
    private function switchNow(SubscriptionItem $item, Price $newPrice, CarbonImmutable $at, bool $creditOld = false): SubscriptionItem
    {
        return DB::transaction(function () use ($item, $newPrice, $at, $creditOld): SubscriptionItem {
            $sub = $item->subscription;
            $period = $item->current_period;
            $qty = (float) $item->quantity;

            if ($creditOld && $period !== null) {
                $unusedOld = $this->prorator->for($period, $at, $item->price->amountFor($qty))->amount();
                $this->prorationCharge($item, $sub, LineKind::Credit, $unusedOld->negated(), 'Unused '.($item->price->product->name ?? 'plan'));
            }

            $item->forceFill(['price_id' => $newPrice->id, 'product_id' => $newPrice->product_id])->save();

            return $item->refresh();
        });
    }

    /**
     * Cancel a subscription. $at is `now` (immediate), `period_end` (the current
     * cycle's end), or a specific CarbonImmutable boundary date (e.g. a later term
     * end). Scheduled cancellations honour the product's notice window: cancelling
     * to a boundary that is within `cancel_notice_days` of now throws. Scheduled
     * cancellations are enacted by processDueCancellations() (run via meteric:run);
     * billing stops at the boundary. No automatic refund.
     */
    /**
     * @param  array<string,mixed>  $meta  optional cancellation data (e.g. a reason), stored on the subscription metadata
     */
    public function cancel(Subscription $sub, string|CarbonImmutable $at = 'period_end', ?CarbonImmutable $when = null, array $meta = []): Subscription
    {
        $when ??= $this->clock->now();
        $metadata = $this->withCancellationMeta($sub, $meta);

        if ($at === 'now') {
            $sub = DB::transaction(function () use ($sub, $when, $metadata): Subscription {
                $sub->items()->update(['state' => ItemState::Canceled->value, 'ends_at' => $when]);
                $sub->forceFill(['state' => SubscriptionState::Canceled, 'canceled_at' => $when, 'metadata' => $metadata])->save();

                return $sub->refresh();
            });

            SubscriptionCanceled::dispatch($sub);

            return $sub;
        }

        $target = $at instanceof CarbonImmutable ? $at : ($sub->current_period?->end ?? $when);

        $notice = $this->noticeDays($sub);
        if ($notice > 0 && $when->greaterThan($target->subDays($notice))) {
            throw new \InvalidArgumentException(
                "Cancelling at {$target->toDateString()} needs {$notice} days notice; the cutoff was {$target->subDays($notice)->toDateString()}."
            );
        }

        $sub->forceFill(['cancel_at' => $target, 'metadata' => $metadata])->save();

        SubscriptionCancellationScheduled::dispatch($sub, $target, $meta);

        return $sub;
    }

    /**
     * @param  array<string,mixed>  $meta
     * @return array<string,mixed>
     */
    private function withCancellationMeta(Subscription $sub, array $meta): array
    {
        $metadata = $sub->metadata ?? [];
        if ($meta !== []) {
            $metadata['cancellation'] = $meta;
        }

        return $metadata;
    }

    /** Days of notice required to cancel: the strictest across the active items' products. */
    public function noticeDays(Subscription $sub): int
    {
        return (int) $sub->items()->where('state', ItemState::Active->value)->with('product')->get()
            ->map(fn (SubscriptionItem $i) => $i->product?->cancelNoticeDays() ?? 0)
            ->max();
    }

    /**
     * The next cancellable term boundaries (for a "cancel at end of period N"
     * dropdown). Returns up to $count future period ends that still satisfy the
     * notice window. UI renders them; the system enforces them.
     *
     * @return list<CarbonImmutable>
     */
    public function cancellationOptions(Subscription $sub, int $count = 3): array
    {
        $period = $sub->current_period;
        $item = $sub->items()->where('state', ItemState::Active->value)->get()
            ->first(fn (SubscriptionItem $i) => $i->price->isRecurring());
        if ($period === null || $item === null) {
            return [];
        }

        $rule = $item->price->recurrence();
        $notice = $this->noticeDays($sub);
        $now = $this->clock->now();

        $out = [];
        $boundary = $period->end;
        for ($i = 0; count($out) < $count && $i < $count * 6; $i++) {
            $cutoff = $notice > 0 ? $boundary->subDays($notice) : $boundary;
            if ($now->lessThanOrEqualTo($cutoff)) {
                $out[] = $boundary;
            }
            $boundary = $rule->period($boundary)->end;
        }

        return $out;
    }

    /**
     * Enact scheduled cancellations whose cancel_at has passed: cancel the
     * subscription at that boundary and fire SubscriptionCanceled. Idempotent
     * (only billable subscriptions are touched). Run via meteric:run.
     */
    public function processDueCancellations(?CarbonImmutable $at = null): int
    {
        $at ??= $this->clock->now();
        $count = 0;

        Subscription::query()
            ->whereNotNull('cancel_at')
            ->where('cancel_at', '<=', $at)
            ->whereIn('state', [SubscriptionState::Active->value, SubscriptionState::Trialing->value, SubscriptionState::PastDue->value])
            ->each(function (Subscription $sub) use (&$count): void {
                $end = $sub->cancel_at;
                DB::transaction(function () use ($sub, $end): void {
                    $sub->items()->update(['state' => ItemState::Canceled->value, 'ends_at' => $end]);
                    $sub->forceFill(['state' => SubscriptionState::Canceled, 'canceled_at' => $end])->save();
                });
                SubscriptionCanceled::dispatch($sub->refresh());
                $count++;
            });

        return $count;
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
            'line_group' => $item->id,
            'description' => $desc,
            'quantity' => $item->quantity,
            'unit' => $item->price->interval?->value,
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
