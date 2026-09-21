<?php

declare(strict_types=1);

namespace Meteric\Models;

use Brick\Money\Money;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Meteric\Support\Models;

/**
 * @property int $amount_minor
 * @property string $currency
 */
class Payment extends MetericModel
{
    protected string $baseTable = 'payments';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'received_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<BillingAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Models::for(BillingAccount::class), 'account_id');
    }

    /** @return HasMany<PaymentAllocation, $this> */
    public function allocations(): HasMany
    {
        return $this->hasMany(Models::for(PaymentAllocation::class), 'payment_id');
    }

    /** @return HasMany<Refund, $this> */
    public function refunds(): HasMany
    {
        return $this->hasMany(Models::for(Refund::class), 'payment_id');
    }

    public function amount(): Money
    {
        return Money::ofMinor($this->amount_minor, $this->currency);
    }

    /** Minor units already returned against this payment. */
    public function refundedMinor(): int
    {
        return (int) $this->refunds()->sum('amount_minor');
    }

    /** Minor units of this payment still applied to invoices, reversals netted off. */
    public function allocatedMinor(): int
    {
        return (int) $this->allocations()->sum('amount_minor');
    }

    /** Minor units of this payment taken back off the invoices it paid, positive. */
    public function reversedMinor(): int
    {
        return (int) abs((int) $this->allocations()->whereNotNull('reverses_allocation_id')->sum('amount_minor'));
    }

    /** Whether nothing this payment allocated is applied any more. */
    public function isReversed(): bool
    {
        return $this->reversedMinor() > 0 && $this->allocatedMinor() === 0;
    }

    /** What may still be refunded: the payment less what went back already. */
    public function refundable(): Money
    {
        return Money::ofMinor(max(0, $this->amount_minor - $this->refundedMinor()), $this->currency);
    }
}
