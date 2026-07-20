<?php

declare(strict_types=1);

namespace Meteric\Models;

use Brick\Money\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Meteric\Casts\MoneyCast;
use Meteric\Casts\PeriodCast;
use Meteric\Enums\BillingMode;
use Meteric\Enums\ChargeState;
use Meteric\Enums\LineKind;
use Meteric\Support\Period;

/**
 * Money owed — the source of truth. Accrues independently of invoicing.
 *
 * @property string $id
 * @property ChargeState $state
 * @property BillingMode $billing_mode
 * @property LineKind $kind
 * @property ?string $title
 * @property ?string $group
 * @property ?string $line_group
 * @property ?string $description
 * @property ?string $unit
 * @property Money $amount
 * @property int $amount_minor
 * @property string $currency
 * @property ?Period $covers
 * @property array $metadata
 */
class Charge extends MetericModel
{
    use SoftDeletes;

    protected string $baseTable = 'charges';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'state' => ChargeState::class,
            'deleted_at' => 'immutable_datetime',
            'billing_mode' => BillingMode::class,
            'kind' => LineKind::class,
            'quantity' => 'float',
            'unit_minor' => 'integer',
            'unit_rate' => 'string',
            'amount_minor' => 'integer',
            'amount' => MoneyCast::class.':amount_minor,currency',
            'covers' => PeriodCast::class,
            'version' => 'integer',
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<BillingAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(BillingAccount::class, 'account_id');
    }

    /** @return BelongsTo<Subscription, $this> */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class, 'subscription_id');
    }

    public function money(): Money
    {
        return Money::ofMinor($this->amount_minor, $this->currency);
    }

    public function isCredit(): bool
    {
        return $this->amount_minor < 0;
    }

    /**
     * Flip to invoiced: a line now references this charge on a non-void invoice.
     * The charge<->invoice link lives on invoice_lines.charge_id, not here.
     *
     * The state guard is defense in depth behind the FOR UPDATE lock the caller
     * holds: a charge can only leave the pending pool once, so a competing run
     * that already invoiced it cannot be silently re-billed here.
     */
    public function markInvoiced(): void
    {
        $flipped = static::query()
            ->whereKey($this->getKey())
            ->where('state', ChargeState::Pending->value)
            ->update(['state' => ChargeState::Invoiced->value]);

        if ($flipped === 0) {
            throw new \RuntimeException("Charge {$this->getKey()} was not pending when billed; concurrent run detected.");
        }

        $this->setAttribute('state', ChargeState::Invoiced)->syncOriginalAttribute('state');
    }

    /** Flip invoiced → settled once the invoice carrying this charge is paid. */
    public function markSettled(): void
    {
        if ($this->state === ChargeState::Settled) {
            return;
        }

        $this->update(['state' => ChargeState::Settled]);
    }

    /**
     * Return a charge to the billable pool when its last live line is removed
     * (void or draft line deletion). A settled or discarded charge never reverts.
     */
    public function revertToPending(): void
    {
        if ($this->trashed() || $this->state === ChargeState::Settled) {
            return;
        }

        $this->update(['state' => ChargeState::Pending]);
    }

    public function void(): void
    {
        $this->update(['state' => ChargeState::Void]);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('state', ChargeState::Pending->value);
    }
}
