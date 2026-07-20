<?php

declare(strict_types=1);

namespace Meteric\Models;

use Brick\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Meteric\Enums\AnchorMode;
use Meteric\Enums\FirstPeriodPolicy;
use Meteric\Enums\OrderState;
use Meteric\Support\Models;

/**
 * A persisted, immutable checkout: the frozen intended subscription, held in the
 * `contents` jsonb cart. When paid in full it materializes a real Subscription +
 * a Paid invoice. The frozen amounts inside `contents` are the source of truth,
 * so later catalog price changes do not move a pending order's figures.
 *
 * @property string $id
 * @property string $account_id
 * @property string $customer_type
 * @property string $customer_id
 * @property string $currency
 * @property OrderState $state
 * @property AnchorMode $anchor_mode
 * @property ?int $anchor_day
 * @property FirstPeriodPolicy $first_period
 * @property int $trial_days
 * @property int $subtotal_minor
 * @property int $tax_minor
 * @property int $total_minor
 * @property int $recurring_total_minor
 * @property list<array<string,mixed>> $contents
 * @property array $quote_snapshot
 * @property string $token
 * @property ?string $idempotency_key
 * @property ?string $invoice_id
 * @property ?string $subscription_id
 * @property ?CarbonImmutable $expires_at
 * @property ?CarbonImmutable $paid_at
 * @property ?CarbonImmutable $converted_at
 * @property ?CarbonImmutable $canceled_at
 * @property array $metadata
 */
class Order extends MetericModel
{
    protected string $baseTable = 'orders';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'state' => OrderState::class,
            'anchor_mode' => AnchorMode::class,
            'anchor_day' => 'integer',
            'first_period' => FirstPeriodPolicy::class,
            'trial_days' => 'integer',
            'subtotal_minor' => 'integer',
            'tax_minor' => 'integer',
            'total_minor' => 'integer',
            'recurring_total_minor' => 'integer',
            'contents' => 'array',
            'quote_snapshot' => 'array',
            'expires_at' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime',
            'converted_at' => 'immutable_datetime',
            'canceled_at' => 'immutable_datetime',
            'version' => 'integer',
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<BillingAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Models::for(BillingAccount::class), 'account_id');
    }

    public function customer(): MorphTo
    {
        return $this->morphTo('customer', 'customer_type', 'customer_id');
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Models::for(Invoice::class), 'invoice_id');
    }

    /** @return BelongsTo<Subscription, $this> */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Models::for(Subscription::class), 'subscription_id');
    }

    /** Gross total owed at checkout (subtotal + tax). */
    public function total(): Money
    {
        return Money::ofMinor($this->total_minor, $this->currency);
    }

    public function isPending(): bool
    {
        return $this->state === OrderState::Pending;
    }

    public function isConverted(): bool
    {
        return $this->state === OrderState::Converted;
    }
}
