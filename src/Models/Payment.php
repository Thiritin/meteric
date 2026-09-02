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

    /** What may still be refunded: the payment less what went back already. */
    public function refundable(): Money
    {
        return Money::ofMinor(max(0, $this->amount_minor - $this->refundedMinor()), $this->currency);
    }
}
