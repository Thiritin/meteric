<?php

declare(strict_types=1);

namespace Meteric\Subscriptions;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Meteric\Contracts\Clock;
use Meteric\Enums\AnchorMode;
use Meteric\Enums\CheckoutState;
use Meteric\Enums\FirstPeriodPolicy;
use Meteric\Events\CheckoutCreated;
use Meteric\Models\BillingAccount;
use Meteric\Models\Order;
use Meteric\Models\Price;
use Meteric\Pricing\CheckoutPricer;
use Meteric\Support\Models;

/**
 * Fluent checkout creation. Mirrors SubscriptionBuilder, but instead of starting
 * a subscription it freezes the cart into a single pending Order row: contents +
 * computed minor amounts + a token. add() opens a new item; addon() and option()
 * attach to the item most recently added. No Subscription/Charge/Invoice exists
 * until the order is paid.
 */
final class CheckoutBuilder
{
    private ?BillingAccount $account = null;

    private ?Model $customer = null;

    private ?string $currency = null;

    private AnchorMode $anchorMode = AnchorMode::Signup;

    private ?int $anchorDay = null;

    private FirstPeriodPolicy $firstPeriod = FirstPeriodPolicy::ProrateOnly;

    private int $trialDays = 0;

    private ?CarbonImmutable $at = null;

    private ?string $idempotencyKey = null;

    private ?int $ttlMinutes;

    /** @var list<array<string,mixed>> */
    private array $items = [];

    public function __construct(
        private Clock $clock,
        private CheckoutPricer $pricer,
        string $defaultCurrency = 'EUR',
        ?int $ttlMinutes = null,
    ) {
        $this->currency = $defaultCurrency;
        $this->ttlMinutes = $ttlMinutes;
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

    public function idempotencyKey(string $key): self
    {
        $this->idempotencyKey = $key;

        return $this;
    }

    /** Minutes until a pending order expires. Null leaves the configured default. */
    public function expiresIn(?int $minutes): self
    {
        $this->ttlMinutes = $minutes;

        return $this;
    }

    public function add(Price $price, float $qty = 1, ?Model $resource = null, ?string $label = null, ?string $group = null): self
    {
        $this->items[] = [
            'price' => $price,
            'qty' => $qty,
            'resource' => $resource,
            'label' => $label,
            'group' => $group,
            'addons' => [],
            'options' => [],
        ];

        return $this;
    }

    /** Attach an addon to the item most recently added. */
    public function addon(Price $price, ?string $group = null, float $qty = 1): self
    {
        $this->items[$this->currentKey()]['addons'][] = ['price' => $price, 'group' => $group, 'qty' => $qty];

        return $this;
    }

    /** Attach a configurable option to the item most recently added (value + label both frozen). */
    public function option(string $key, string $value, string $type, ?Price $price = null, float $qty = 1, ?float $min = null, ?float $max = null, ?string $label = null): self
    {
        $this->items[$this->currentKey()]['options'][] = [
            'key' => $key,
            'value' => $value,
            'type' => $type,
            'price' => $price,
            'qty' => $qty,
            'min' => $min,
            'max' => $max,
            'label' => $label,
        ];

        return $this;
    }

    public function create(): Order
    {
        if ($this->items === []) {
            throw new \LogicException('An order needs at least one item.');
        }

        $at = $this->at ?? $this->clock->now();
        $account = $this->account ?? $this->resolveAccount();
        $currency = $this->currency ?? $account->currency;

        $priced = $this->pricer->price(
            cart: $this->items,
            currency: $currency,
            at: $at,
            anchorMode: $this->anchorMode,
            anchorDay: $this->anchorDay,
            firstPeriod: $this->firstPeriod,
            trialDays: $this->trialDays,
            taxContext: $account->taxContext(),
        );

        if ($priced->totalMinor < 0) {
            throw new \InvalidArgumentException('An order total cannot be negative.');
        }

        $order = Models::query(Order::class)->create([
            'account_id' => $account->id,
            'customer_type' => $this->customer?->getMorphClass() ?? $account->owner_type,
            'customer_id' => $this->customer?->getKey() ?? $account->owner_id,
            'currency' => $currency,
            'state' => CheckoutState::Pending,
            'anchor_mode' => $this->anchorMode,
            'anchor_day' => $this->anchorDay,
            'first_period' => $this->firstPeriod,
            'trial_days' => $this->trialDays,
            'subtotal_minor' => $priced->subtotalMinor,
            'tax_minor' => $priced->taxMinor,
            'total_minor' => $priced->totalMinor,
            'recurring_total_minor' => $priced->recurringTotalMinor,
            'contents' => $priced->contents,
            'quote_snapshot' => $priced->quoteSnapshot,
            'token' => Str::random(40),
            'idempotency_key' => $this->idempotencyKey,
            'expires_at' => $this->ttlMinutes !== null && $this->ttlMinutes > 0 ? $at->addMinutes($this->ttlMinutes) : null,
        ]);

        CheckoutCreated::dispatch($order);

        return $order;
    }

    private function currentKey(): int
    {
        if ($this->items === []) {
            throw new \LogicException('Call add() before attaching addons or options.');
        }

        return array_key_last($this->items);
    }

    private function resolveAccount(): BillingAccount
    {
        if ($this->customer === null) {
            throw new \LogicException('openCheckout() needs an account() or for(customer).');
        }

        return Models::query(BillingAccount::class)->firstOrCreate(
            ['owner_type' => $this->customer->getMorphClass(), 'owner_id' => $this->customer->getKey()],
            ['currency' => $this->currency],
        );
    }
}
