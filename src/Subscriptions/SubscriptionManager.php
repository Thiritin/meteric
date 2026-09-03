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
use Meteric\Exceptions\PeriodNotRebasable;
use Meteric\Exceptions\TermNotSwitchable;
use Meteric\Meteric;
use Meteric\Models\BillingPeriod;
use Meteric\Models\Charge;
use Meteric\Models\Invoice;
use Meteric\Models\InvoiceLine;
use Meteric\Models\Price;
use Meteric\Models\Subscription;
use Meteric\Models\SubscriptionItem;
use Meteric\Proration\Prorator;
use Meteric\Support\Models;
use Meteric\Support\Period;
use Meteric\Usage\UsageRollup;

/** Lifecycle operations on existing subscriptions: renew, change plan, cancel. */
final class SubscriptionManager
{
    public function __construct(
        private Clock $clock,
        private Prorator $prorator,
        private ChargeAccruer $accruer,
        private UsageRollup $usage,
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

        Models::query(Invoice::class)
            ->whereIn('state', [InvoiceState::Open->value, InvoiceState::PartiallyPaid->value])
            ->whereNotNull('due_at')
            ->where('due_at', '<', $at)
            ->whereNull('overdue_at')   // fire once per invoice; safe to run every few minutes
            ->each(function (Invoice $invoice) use ($at, &$count): void {
                $invoice->forceFill(['overdue_at' => $at])->save();
                foreach ($invoice->billedSubscriptions() as $sub) {
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

        // A deferred change landing: the item moves onto another price, so a
        // bespoke amount set against the old one goes with it. See
        // `SubscriptionItem::getPriceAttribute()`.
        $item->forceFill([
            'price_id' => $change['price_id'],
            'price_override_id' => null,
            'pending_change' => null,
        ])->save();
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
            // The override belongs to the price it was set against, so moving
            // to another price drops it rather than carrying a bespoke amount
            // onto a plan nobody agreed it for.
            $item->forceFill([
                'price_id' => $newPrice->id,
                'product_id' => $newPrice->product_id,
                'price_override_id' => null,
            ])->save();

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
     * Refund downgrade: swap now and issue a credit note for the net of the
     * rest of the cycle, the unused old value less the cheaper plan's
     * remainder, against the invoice that billed the current period (a refund
     * document; a host listener moves the money). With nothing invoiced yet
     * there is nothing to refund, so the net becomes a pending credit on the
     * next invoice. Equal prices write nothing.
     */
    private function refundDowngrade(SubscriptionItem $item, Price $newPrice, CarbonImmutable $at): SubscriptionItem
    {
        return DB::transaction(function () use ($item, $newPrice, $at): SubscriptionItem {
            $sub = $item->subscription;
            $period = $item->current_period;
            $qty = (float) $item->quantity;

            if ($period !== null) {
                $net = $this->prorator->swap($period, $at, $item->price->amountFor($qty), $newPrice->amountFor($qty));

                if ($net->isNegative()) {
                    $invoice = $this->periodInvoice($item);
                    if ($invoice !== null) {
                        app(Meteric::class)->creditNote($invoice, $net->abs(), 'Downgrade to '.($newPrice->product->name ?? 'plan'));
                    } else {
                        $this->prorationCharge($item, LineKind::Credit, $net, 'Downgrade to '.($newPrice->product->name ?? 'plan'));
                    }
                }
            }

            // The override belongs to the price it was set against, so moving
            // to another price drops it rather than carrying a bespoke amount
            // onto a plan nobody agreed it for.
            $item->forceFill([
                'price_id' => $newPrice->id,
                'product_id' => $newPrice->product_id,
                'price_override_id' => null,
            ])->save();

            return $item->refresh();
        });
    }

    /** The live (non-void) invoice that billed this item's current period, if any. */
    private function periodInvoice(SubscriptionItem $item): ?Invoice
    {
        if ($item->current_period === null) {
            return null;
        }

        $chargeIds = Models::query(Charge::class)
            ->where('origin_type', 'subscription_item')
            ->where('origin_id', $item->id)
            ->whereRaw('covers && ?::tstzrange', [$item->current_period->toRange()])
            ->latest('created_at')
            ->pluck('id');

        return Models::query(Invoice::class)
            ->whereIn('id', Models::query(InvoiceLine::class)->whereIn('charge_id', $chargeIds)->select('invoice_id'))
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

                $this->prorationCharge($item, LineKind::Credit, $unusedOld->negated(), 'Unused '.($item->price->product->name ?? 'plan'));
                $this->prorationCharge($item, LineKind::Prorated, $proratedNew, 'Upgrade '.($newPrice->product->name ?? 'plan'));
            }

            // The override belongs to the price it was set against, so moving
            // to another price drops it rather than carrying a bespoke amount
            // onto a plan nobody agreed it for.
            $item->forceFill([
                'price_id' => $newPrice->id,
                'product_id' => $newPrice->product_id,
                'price_override_id' => null,
            ])->save();

            return $item->refresh();
        });
    }

    /**
     * Swap the plan immediately. With creditOld (downgrade `credit`) the rest of
     * the cycle is settled as one net pending line: the unused old value less the
     * new plan's prorated remainder. Plain discard writes nothing.
     */
    private function switchNow(SubscriptionItem $item, Price $newPrice, CarbonImmutable $at, bool $creditOld = false): SubscriptionItem
    {
        return DB::transaction(function () use ($item, $newPrice, $at, $creditOld): SubscriptionItem {
            $sub = $item->subscription;
            $period = $item->current_period;
            $qty = (float) $item->quantity;

            if ($creditOld && $period !== null) {
                // Net of the swap for the rest of the cycle: the unused old value
                // comes back, the new plan's remainder is owed. One rounding, one
                // line, never both signs. Zero (equal prices) writes nothing.
                $net = $this->prorator->swap($period, $at, $item->price->amountFor($qty), $newPrice->amountFor($qty));
                if ($net->isNegative()) {
                    $this->prorationCharge($item, LineKind::Credit, $net, 'Downgrade to '.($newPrice->product->name ?? 'plan'));
                } elseif ($net->isPositive()) {
                    $this->prorationCharge($item, LineKind::Prorated, $net, 'Change to '.($newPrice->product->name ?? 'plan'));
                }
            }

            // The override belongs to the price it was set against, so moving
            // to another price drops it rather than carrying a bespoke amount
            // onto a plan nobody agreed it for.
            $item->forceFill([
                'price_id' => $newPrice->id,
                'product_id' => $newPrice->product_id,
                'price_override_id' => null,
            ])->save();

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

        Models::query(Subscription::class)
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

    /**
     * Move an item's period end to $newEnd, keeping its start: [start, newEnd).
     * The subscription's period follows the earliest active item. With $prorate
     * the span between the old end and the new one is charged at the item's
     * full period rate as one pending line: Prorated when extended, Credit
     * when shortened. Without it the dates move and no money does.
     *
     * @throws PeriodNotRebasable when the item is not active, has no period,
     *                            is not recurring, or $newEnd is not after the start
     */
    /**
     * Bill this item a bespoke amount instead of what its product publishes.
     *
     * The override is a price row of its own (`Price::asOverride()`), a copy of
     * the catalog price with a different amount, so everything that reads
     * `$item->price` sees a price behaving exactly like the one it replaces:
     * the same product, interval, billing mode and pricing model, priced
     * differently. Proration, plan swaps, the accruer and relative addon prices
     * all pick it up without knowing an override exists.
     *
     * **Setting one does not move money.** It changes what the *next* accrual
     * bills; the running period was already charged at whatever it was charged.
     * A caller that wants the difference settled mid-period rebases or prorates
     * on top, deliberately.
     *
     * Overriding twice replaces the amount and leaves the previous override row
     * in place: an invoice line written against it has to keep resolving.
     */
    public function overridePrice(SubscriptionItem $item, int $amountMinor): SubscriptionItem
    {
        $base = $item->price()->first()
            ?? throw new \InvalidArgumentException('That item has no price to override.');

        return DB::transaction(function () use ($item, $base, $amountMinor): SubscriptionItem {
            $item->forceFill(['price_override_id' => $base->asOverride($amountMinor)->id])->save();

            return $item->refresh();
        });
    }

    /**
     * Back to the product's own price. The override row is kept, never deleted:
     * charges and invoice lines already written against it must still resolve.
     */
    public function clearPriceOverride(SubscriptionItem $item): SubscriptionItem
    {
        $item->forceFill(['price_override_id' => null])->save();

        return $item->refresh();
    }

    public function rebasePeriod(SubscriptionItem $item, CarbonImmutable $newEnd, bool $prorate = false, ?CarbonImmutable $at = null): SubscriptionItem
    {
        $preview = $this->previewRebase($item, $newEnd, $at);

        return DB::transaction(function () use ($item, $preview, $prorate): SubscriptionItem {
            $item->forceFill(['current_period' => $preview->period])->save();

            if ($prorate && $preview->kind !== null && $preview->amount->isPositive()) {
                $amount = $preview->kind === LineKind::Credit ? $preview->amount->negated() : $preview->amount;
                $verb = $preview->kind === LineKind::Credit ? 'Shortened ' : 'Extended ';
                $this->prorationCharge($item, $preview->kind, $amount, $verb.($item->price->product->name ?? 'plan'));
            }

            $sub = $item->subscription;
            $sub->forceFill(['current_period' => $this->earliestPeriod($sub)])->save();

            return $item->refresh();
        });
    }

    /**
     * Switch an item onto another term mid-period: settle the running period
     * and open a new one from $at on the new price's term.
     *
     * A plan change prorates inside the period it is in and leaves the term
     * alone, which is the wrong shape for monthly to yearly. This closes the
     * running period at $at instead:
     *
     *  1. The closing window's metered usage is rolled up, so it is billed with
     *     the period it belongs to rather than carried into the new one.
     *  2. What the closing window was billed and will not deliver comes back as
     *     one `Unused <plan>` credit. It is the unused fraction of everything
     *     the window billed, the period, its options, its addons and its
     *     discounts, because the new period bills all of them again.
     *  3. The item moves to the new price and the new period is accrued whole
     *     from $at on the new term.
     *
     * Every figure is pending, so the caller decides when to invoice. No
     * document is produced here.
     *
     * @throws TermNotSwitchable
     */
    public function switchTerm(SubscriptionItem $item, Price $newPrice, ?CarbonImmutable $at = null): SubscriptionItem
    {
        $at ??= $this->clock->now();
        $preview = $this->previewTermSwitch($item, $newPrice, $at);
        $oldName = $item->price->product->name ?? 'plan';

        return DB::transaction(function () use ($item, $newPrice, $at, $preview, $oldName): SubscriptionItem {
            $this->usage->rollup($item, $preview->closing);

            if ($preview->unused->isPositive()) {
                $this->prorationCharge($item, LineKind::Credit, $preview->unused->negated(), 'Unused '.$oldName);
            }

            // The closing window keeps its guard, shortened to what it actually
            // covers. Without this the window already billed still overlaps the
            // period opening inside it, and the new period would reserve
            // nothing and bill nothing.
            $this->closeReservations($item, $at);

            $item->forceFill([
                'price_id' => $newPrice->id,
                'product_id' => $newPrice->product_id,
                'pending_change' => null,
            ])->save();
            $item->refresh()->load('price');

            $this->accruer->accrue($item, new BillingPlan(
                [new PlannedPeriod($preview->opening, LineKind::Recurring)],
                $preview->opening,
            ));

            $sub = $item->subscription;
            $sub->forceFill(['current_period' => $this->earliestPeriod($sub)])->save();

            return $item->refresh();
        });
    }

    /** What switchTerm() would write, without writing it. Same guards. */
    public function previewTermSwitch(SubscriptionItem $item, Price $newPrice, ?CarbonImmutable $at = null): TermSwitchPreview
    {
        $at ??= $this->clock->now();
        $period = $item->current_period;

        if ($item->state !== ItemState::Active) {
            throw new TermNotSwitchable("Item {$item->id} is {$item->state->value}; only an active item can switch term.");
        }
        if ($period === null) {
            throw new TermNotSwitchable("Item {$item->id} has no current period.");
        }
        if (! $item->price->isRecurring() || ! $newPrice->isRecurring()) {
            throw new TermNotSwitchable("Item {$item->id} switches term between recurring prices only.");
        }
        if (! $period->contains($at)) {
            throw new TermNotSwitchable("{$at->toIso8601String()} is outside the item's current period; there is no running period to settle.");
        }

        $closing = new Period($period->start, $at);
        $opening = $newPrice->recurrence()->period($at);

        if ($this->periodBilledFrom($item, $opening, $at)) {
            throw new TermNotSwitchable("Item {$item->id} has a period already billed inside {$opening->toRange()}.");
        }

        $currency = $item->subscription->currency;
        $billed = $this->billedForWindow($item, $period);
        $unused = $billed->isPositive()
            ? $this->prorator->for($period, $at, $billed)->amount()
            : Money::ofMinor(0, $currency);

        $usage = Money::ofMinor(0, $currency);
        $usageLines = [];

        foreach ($this->usage->rate($item, $closing) as $rated) {
            $usage = $usage->plus($rated['amount']);
            $usageLines[] = [
                'dimension' => $rated['dimension']->key,
                'used' => (float) $rated['used'],
                'unit' => $rated['dimension']->unit,
                'amount_minor' => $rated['amount']->getMinorAmount()->toInt(),
            ];
        }

        return new TermSwitchPreview(
            $closing,
            $opening,
            $unused,
            $usage,
            $this->accruer->quote($item, $newPrice, $opening),
            $usageLines,
        );
    }

    /**
     * What the item's window has been billed: the period, its options, its
     * addons and its discounts, and never metered usage, which pays for what
     * has already happened and is not unused.
     */
    private function billedForWindow(SubscriptionItem $item, Period $window): Money
    {
        $minor = (int) Models::query(Charge::class)
            ->where('line_group', $item->id)
            ->where('state', '<>', ChargeState::Void->value)
            ->where('kind', '<>', LineKind::Usage->value)
            ->whereRaw('covers && ?::tstzrange', [$window->toRange()])
            ->sum('amount_minor');

        return Money::ofMinor($minor, $item->subscription->currency);
    }

    /** Whether a window the item has already billed starts on or after $at. */
    private function periodBilledFrom(SubscriptionItem $item, Period $window, CarbonImmutable $at): bool
    {
        return Models::query(BillingPeriod::class)
            ->where('item_id', $item->id)
            ->whereNull('dimension_id')
            ->whereRaw('lower(covers) >= ?', [$at])
            ->whereRaw('covers && ?::tstzrange', [$window->toRange()])
            ->exists();
    }

    /** Shorten every billed window that runs past $at to end there, so what follows is open to bill. */
    private function closeReservations(SubscriptionItem $item, CarbonImmutable $at): void
    {
        $rows = Models::query(BillingPeriod::class)
            ->where('item_id', $item->id)
            ->whereRaw('lower(covers) < ? and upper(covers) > ?', [$at, $at])
            ->get();

        foreach ($rows as $row) {
            $row->forceFill(['covers' => new Period($row->covers->start, $at)])->save();
        }
    }

    /** What rebasePeriod() would write, without writing it. Same guards. */
    public function previewRebase(SubscriptionItem $item, CarbonImmutable $newEnd, ?CarbonImmutable $at = null): RebasePreview
    {
        $period = $item->current_period;

        if ($item->state !== ItemState::Active) {
            throw new PeriodNotRebasable("Item {$item->id} is {$item->state->value}; only an active item can be rebased.");
        }
        if ($period === null) {
            throw new PeriodNotRebasable("Item {$item->id} has no current period.");
        }
        if (! $item->price->isRecurring()) {
            throw new PeriodNotRebasable("Item {$item->id} is not recurring.");
        }
        if ($newEnd <= $period->start) {
            throw new PeriodNotRebasable("New end {$newEnd->toIso8601String()} is not after the period start {$period->start->toIso8601String()}.");
        }

        $target = new Period($period->start, $newEnd);
        $full = $item->periodAmount();

        if ($newEnd > $period->end) {
            return new RebasePreview($target, LineKind::Prorated, $this->spanAmount($item, new Period($period->end, $newEnd), $full));
        }
        if ($newEnd < $period->end) {
            return new RebasePreview($target, LineKind::Credit, $this->spanAmount($item, new Period($newEnd, $period->end), $full));
        }

        return new RebasePreview($target, null, Money::ofMinor(0, $full->getCurrency()));
    }

    /**
     * A span priced at the full period rate: whole cycles at the full amount,
     * then the remainder as the used part of the cycle it starts, prorated
     * through the configured unit so it matches every other proration.
     */
    private function spanAmount(SubscriptionItem $item, Period $span, Money $full): Money
    {
        $rule = $item->price->recurrence();
        $amount = Money::ofMinor(0, $full->getCurrency());
        $cursor = $span->start;

        while ($rule->nextEnd($cursor) <= $span->end) {
            $amount = $amount->plus($full);
            $cursor = $rule->nextEnd($cursor);
        }

        if ($cursor < $span->end) {
            $cycle = $rule->period($cursor);
            $unused = $this->prorator->for($cycle, $span->end, $full)->amount();
            $amount = $amount->plus($full->minus($unused));
        }

        return $amount;
    }

    private function prorationCharge(SubscriptionItem $item, LineKind $kind, Money $amount, string $desc): void
    {
        Charge::pendingForItem($item, [
            'kind' => $kind,
            'description' => $desc,
            'quantity' => $item->quantity,
            'unit' => $item->price->interval?->value,
            'unit_minor' => $amount->getMinorAmount()->toInt(),
            'amount_minor' => $amount->getMinorAmount()->toInt(),
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
