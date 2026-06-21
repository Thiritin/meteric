<?php

declare(strict_types=1);

namespace Meteric\Models;

use Brick\Money\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
    protected $table = 'meteric_charges';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'state' => ChargeState::class,
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

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    public function money(): Money
    {
        return Money::ofMinor($this->amount_minor, $this->currency);
    }

    public function isCredit(): bool
    {
        return $this->amount_minor < 0;
    }

    /** Flip pending → invoiced atomically once a driver confirmed the invoice. */
    public function markInvoiced(Invoice $invoice): void
    {
        $this->update([
            'state' => ChargeState::Invoiced,
            'invoice_id' => $invoice->id,
        ]);
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
