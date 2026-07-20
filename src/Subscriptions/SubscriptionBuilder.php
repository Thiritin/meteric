<?php

declare(strict_types=1);

namespace Meteric\Subscriptions;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Meteric\Anchoring\PeriodPlanner;
use Meteric\Charges\ChargeAccruer;
use Meteric\Contracts\Clock;
use Meteric\Enums\AnchorMode;
use Meteric\Enums\ChargeState;
use Meteric\Enums\FirstPeriodPolicy;
use Meteric\Enums\ItemState;
use Meteric\Enums\LineKind;
use Meteric\Enums\SubscriptionState;
use Meteric\Meteric;
use Meteric\Models\BillingAccount;
use Meteric\Models\Charge;
use Meteric\Models\Price;
use Meteric\Models\Subscription;
use Meteric\Models\SubscriptionItem;
use Meteric\Support\Models;
use Meteric\Support\Period;

/**
 * Fluent subscription creation: persists the subscription + items and accrues
 * the first cycle's pending charges (via the planner + accruer). Non-trial
 * subscriptions bill the first cycle now; trials defer to the first renewal.
 */
final class SubscriptionBuilder
{
    private ?BillingAccount $account = null;

    private ?Model $customer = null;

    private ?string $currency = null;

    private AnchorMode $anchorMode = AnchorMode::Signup;

    private ?int $anchorDay = null;

    private FirstPeriodPolicy $firstPeriod = FirstPeriodPolicy::ProrateOnly;

    private int $trialDays = 0;

    private ?CarbonImmutable $at = null;

    /** @var list<array{price:Price,qty:float,resource:?Model,label:?string,group:?string}> */
    private array $items = [];

    public function __construct(
        private Clock $clock,
        private PeriodPlanner $planner,
        private ChargeAccruer $accruer,
        string $defaultCurrency = 'EUR',
    ) {
        $this->currency = $defaultCurrency;
    }

    public function account(BillingAccount $account): self
    {
        $this->account = $account;
        $this->currency = $account->currency;

        return $this;
    }

    public function for(Model $customer): self
    {
        $this->customer = $customer;

        return $this;
    }

    public function currency(string $currency): self
    {
        $this->currency = $currency;

        return $this;
    }

    public function anchor(AnchorMode $mode, ?int $day = null): self
    {
        $this->anchorMode = $mode;
        $this->anchorDay = $day;

        return $this;
    }

    public function firstPeriod(FirstPeriodPolicy $policy): self
    {
        $this->firstPeriod = $policy;

        return $this;
    }

    public function trialDays(int $days): self
    {
        $this->trialDays = $days;

        return $this;
    }

    public function at(CarbonImmutable $at): self
    {
        $this->at = $at;

        return $this;
    }

    public function add(Price $price, float $qty = 1, ?Model $resource = null, ?string $label = null, ?string $group = null): self
    {
        $this->items[] = ['price' => $price, 'qty' => $qty, 'resource' => $resource, 'label' => $label, 'group' => $group];

        return $this;
    }

    public function create(): Subscription
    {
        $at = $this->at ?? $this->clock->now();
        $account = $this->account ?? $this->resolveAccount();
        $trialEnd = $this->trialDays > 0 ? $at->addDays($this->trialDays) : null;
        $signup = $trialEnd ?? $at;

        return DB::transaction(function () use ($at, $account, $trialEnd, $signup): Subscription {
            $sub = Models::query(Subscription::class)->create([
                'account_id' => $account->id,
                'customer_type' => $this->customer?->getMorphClass() ?? $account->owner_type,
                'customer_id' => $this->customer?->getKey() ?? $account->owner_id,
                'currency' => $this->currency,
                'state' => $trialEnd ? SubscriptionState::Trialing : SubscriptionState::Active,
                'anchor_mode' => $this->anchorMode,
                'anchor_day' => $this->anchorDay,
                'first_period' => $this->firstPeriod,
                'trial_end' => $trialEnd,
            ]);

            $ends = [];
            foreach ($this->items as $row) {
                $ends[] = $this->addItem($sub, $row, $signup, deferBilling: (bool) $trialEnd);
            }

            $sub->forceFill(['current_period' => new Period($at, min($ends))])->save();

            return $sub->refresh();
        });
    }

    /** Create the subscription and immediately invoice the first cycle's charges. */
    public function checkout(): Checkout
    {
        $sub = $this->create();
        $account = Models::query(BillingAccount::class)->findOrFail($sub->account_id);
        $invoice = app(Meteric::class)->invoicePending($account);

        return new Checkout($sub, $invoice);
    }

    /** @param array{price:Price,qty:float,resource:?Model,label:?string,group:?string} $row */
    private function addItem(Subscription $sub, array $row, CarbonImmutable $signup, bool $deferBilling): CarbonImmutable
    {
        $price = $row['price'];

        $item = Models::query(SubscriptionItem::class)->create([
            'subscription_id' => $sub->id,
            'product_id' => $price->product_id,
            'price_id' => $price->id,
            'resource_type' => $row['resource']?->getMorphClass(),
            'resource_id' => $row['resource']?->getKey(),
            'label' => $row['label'] ?? null,
            'group' => $row['group'] ?? null,
            'quantity' => $row['qty'],
            'state' => ItemState::Active,
            'activated_at' => $signup,
        ]);
        $item->setRelation('subscription', $sub);
        $item->setRelation('price', $price);

        // One-off purchase: a single immediate charge, no recurrence/period guard.
        if (! $price->isRecurring()) {
            $this->oneOffCharge($sub, $item, $price);
            $item->forceFill(['current_period' => new Period($signup, $signup->addSecond())])->save();

            return $signup->addSecond();
        }

        $plan = $this->planner->plan($signup, $price->recurrence(), $this->anchorMode, $this->anchorDay, $this->firstPeriod);

        if ($deferBilling) {
            // Trial: reserve nothing, just set the period; first renewal bills it.
            $item->forceFill(['current_period' => $plan->ongoing])->save();
        } else {
            $this->accruer->accrue($item, $plan);
        }

        return $plan->ongoing->end;
    }

    private function oneOffCharge(Subscription $sub, SubscriptionItem $item, Price $price): void
    {
        $amount = $price->amountFor((float) $item->quantity);

        Models::query(Charge::class)->create([
            'account_id' => $sub->account_id,
            'subscription_id' => $sub->id,
            'origin_type' => 'subscription_item',
            'origin_id' => $item->id,
            'kind' => LineKind::OneOff,
            'billing_mode' => $item->billingMode(),
            'state' => ChargeState::Pending,
            'title' => $item->lineTitle(),
            'group' => $item->group,
            'line_group' => $item->id,
            'description' => $price->purpose->value === 'setup' ? 'Setup' : null,
            'quantity' => $item->quantity,
            'unit_minor' => $price->amount_minor,
            'amount_minor' => $amount->getMinorAmount()->toInt(),
            'currency' => $sub->currency,
            'idempotency_key' => 'oneoff_'.Str::uuid()->toString(),
        ]);
    }

    private function resolveAccount(): BillingAccount
    {
        if ($this->customer === null) {
            throw new \LogicException('subscribe() needs an account() or for(customer).');
        }

        return Models::query(BillingAccount::class)->firstOrCreate(
            ['owner_type' => $this->customer->getMorphClass(), 'owner_id' => $this->customer->getKey()],
            ['currency' => $this->currency],
        );
    }
}
