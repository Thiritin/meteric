<?php

declare(strict_types=1);

namespace Meteric\Models;

use Brick\Money\Money;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Meteric\Support\Models;

/**
 * Money returned against a payment. The payment and its allocations are left
 * as they were; the refund is its own positive row, so what came in and what
 * went back out both stay readable.
 *
 * @property string $payment_id
 * @property ?string $credit_note_id
 * @property int $amount_minor
 * @property string $currency
 * @property ?string $reference
 */
class Refund extends MetericModel
{
    protected string $baseTable = 'refunds';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'refunded_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Models::for(Payment::class), 'payment_id');
    }

    /** @return BelongsTo<CreditNote, $this> */
    public function creditNote(): BelongsTo
    {
        return $this->belongsTo(Models::for(CreditNote::class), 'credit_note_id');
    }

    public function amount(): Money
    {
        return Money::ofMinor($this->amount_minor, $this->currency);
    }
}
