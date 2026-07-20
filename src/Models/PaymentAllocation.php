<?php

declare(strict_types=1);

namespace Meteric\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Meteric\Support\Models;

class PaymentAllocation extends MetericModel
{
    protected string $baseTable = 'payment_allocations';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer'];
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
}
