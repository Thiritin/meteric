<?php

declare(strict_types=1);

namespace Meteric\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Meteric\Support\Models;

/**
 * Money applied to an invoice, and money taken back off it.
 *
 * A row with no `reverses_allocation_id` is an application and is positive; a
 * row with one is a reversal of that application and is negative. Summing the
 * column over an invoice therefore gives what is allocated to it now, and the
 * row that stopped being true is still there with the date and the ground it
 * stopped on.
 *
 * @property string $id
 * @property string $payment_id
 * @property string $invoice_id
 * @property int $amount_minor
 * @property ?string $reverses_allocation_id
 */
class PaymentAllocation extends MetericModel
{
    protected string $baseTable = 'payment_allocations';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'recorded_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Models::for(Payment::class), 'payment_id');
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Models::for(Invoice::class), 'invoice_id');
    }

    /** The allocation this row takes back, null on an allocation of its own. */
    public function reverses(): BelongsTo
    {
        return $this->belongsTo(Models::for(self::class), 'reverses_allocation_id');
    }

    /** @return HasMany<PaymentAllocation, $this> */
    public function reversals(): HasMany
    {
        return $this->hasMany(Models::for(self::class), 'reverses_allocation_id');
    }

    public function isReversal(): bool
    {
        return $this->reverses_allocation_id !== null;
    }

    /** Minor units of this allocation already taken back, positive. */
    public function reversedMinor(): int
    {
        return (int) abs((int) $this->reversals()->sum('amount_minor'));
    }

    /** @param  Builder<PaymentAllocation>  $query */
    public function scopeApplications(Builder $query): Builder
    {
        return $query->whereNull('reverses_allocation_id');
    }
}
