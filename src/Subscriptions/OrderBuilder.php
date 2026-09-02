<?php

declare(strict_types=1);

namespace Meteric\Subscriptions;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Meteric\Contracts\Clock;
use Meteric\Enums\AnchorMode;
use Meteric\Enums\FirstPeriodPolicy;
use Meteric\Enums\OrderState;
use Meteric\Events\OrderCreated;
use Meteric\Exceptions\CatalogRowInactive;
use Meteric\Models\BillingAccount;
use Meteric\Models\Order;
use Meteric\Models\Price;
use Meteric\Models\ProductAddon;
use Meteric\Models\ProductOptionValue;
use Meteric\Pricing\CheckoutPricer;
use Meteric\Pricing\FrozenCart;
use Meteric\Quoting\Quote;
use Meteric\Support\Models;
use Meteric\Tax\TaxContext;

/**
 * Fluent checkout creation. Mirrors SubscriptionBuilder, but instead of starting
 * a subscription it freezes the cart into a single pending Order row: contents +
 * computed minor amounts + a token. add() opens a new item; addon() and option()
 * attach to the item most recently added. quote() prices the same cart without
 * persisting it, so what a checkout page shows is what create() freezes. No
 * Subscription/Charge/Invoice exists until the order is paid.
 */
final class OrderBuilder
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

    private ?TaxContext $taxContext = null;

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

    /** Price under an explicit tax context instead of the account's profile. */
    public function tax(TaxContext $context): self
    {
        $this->taxContext = $context;

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

    /**
     * Book a catalog addon on the item most recently added: the addon's price is
     * resolved for that item's currency and term, its group key carried over,
     * and the quantity checked against the catalog bounds.
     */
    public function bookAddon(ProductAddon $addon, float $qty = 1): self
    {
        $term = $this->items[$this->currentKey()]['price'];

        if ($addon->product_id !== $term->product_id) {
            throw new \InvalidArgumentException("Addon {$addon->addon->slug} is not offered with product {$term->product->slug}.");
        }
        if (! $addon->active) {
            throw new CatalogRowInactive("Addon {$addon->addon->slug} is no longer offered with product {$term->product->slug}.");
        }
        $this->guardBounds($addon->addon->slug, $qty, $addon->min_qty, $addon->max_qty);

        $price = $addon->priceFor($term);
        if ($price === null) {
            throw new \InvalidArgumentException("Addon {$addon->addon->slug} has no {$term->currency} price for this term.");
        }

        return $this->addon($price, $addon->group_key, $qty);
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

    /**
     * Select a catalog option value on the item most recently added. Reads the
     * key, type, bounds and price off the catalog and checks the quantity.
     */
    public function chooseOption(ProductOptionValue $value, float $qty = 1): self
    {
        $option = $value->option;
        if (! $value->isBookable()) {
            throw new CatalogRowInactive("Option {$option->key} value {$value->value} is no longer offered.");
        }
        $this->guardBounds($option->key, $qty, $option->min_qty, $option->max_qty);

        return $this->option(
            $option->key, $value->value, $option->type->value,
            $value->price, $qty, $option->min_qty, $option->max_qty, $value->label ?? $value->value,
        );
    }

    /**
     * Price the cart as create() would and return the quote, persisting nothing.
     * Tax comes from the explicit context, else the customer's existing account;
     * a customer with no account yet is quoted untaxed.
     */
    public function quote(): Quote
    {
        $at = $this->at ?? $this->clock->now();
        $account = $this->account ?? $this->findAccount();
        $currency = $this->currency ?? $account->currency ?? config('meteric.currency', 'EUR');

        return $this->price($at, $currency, $this->taxContext ?? $account?->taxContext() ?? new TaxContext)->quote;
    }

    public function create(): Order
    {
        $at = $this->at ?? $this->clock->now();
        $account = $this->account ?? $this->resolveAccount();
        $currency = $this->currency ?? $account->currency;

        $priced = $this->price($at, $currency, $this->taxContext ?? $account->taxContext());

        if ($priced->totalMinor < 0) {
            throw new \InvalidArgumentException('An order total cannot be negative.');
        }

        $order = Models::query(Order::class)->create([
            'account_id' => $account->id,
            'customer_type' => $this->customer?->getMorphClass() ?? $account->owner_type,
            'customer_id' => $this->customer?->getKey() ?? $account->owner_id,
            'currency' => $currency,
            'state' => OrderState::Pending,
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

        OrderCreated::dispatch($order);

        return $order;
    }

    private function price(CarbonImmutable $at, string $currency, TaxContext $taxContext): FrozenCart
    {
        if ($this->items === []) {
            throw new \LogicException('An order needs at least one item.');
        }

        return $this->pricer->price(
            cart: $this->items,
            currency: $currency,
            at: $at,
            anchorMode: $this->anchorMode,
            anchorDay: $this->anchorDay,
            firstPeriod: $this->firstPeriod,
            trialDays: $this->trialDays,
            taxContext: $taxContext,
        );
    }

    private function guardBounds(string $what, float $qty, ?float $min, ?float $max): void
    {
        if ($min !== null && $qty < $min) {
            throw new \InvalidArgumentException("{$what} quantity {$qty} is below the minimum {$min}.");
        }
        if ($max !== null && $qty > $max) {
            throw new \InvalidArgumentException("{$what} quantity {$qty} is above the maximum {$max}.");
        }
    }

    private function currentKey(): int
    {
        if ($this->items === []) {
            throw new \LogicException('Call add() before attaching addons or options.');
        }

        return array_key_last($this->items);
    }

    /** The customer's account if one exists; never creates one. */
    private function findAccount(): ?BillingAccount
    {
        if ($this->customer === null) {
            return null;
        }

        return Models::query(BillingAccount::class)
            ->where('owner_type', $this->customer->getMorphClass())
            ->where('owner_id', $this->customer->getKey())
            ->first();
    }

    private function resolveAccount(): BillingAccount
    {
        if ($this->customer === null) {
            throw new \LogicException('createOrder() needs an account() or for(customer).');
        }

        return Models::query(BillingAccount::class)->firstOrCreate(
            ['owner_type' => $this->customer->getMorphClass(), 'owner_id' => $this->customer->getKey()],
            ['currency' => $this->currency],
        );
    }
}
