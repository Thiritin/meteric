<?php

declare(strict_types=1);

namespace Meteric\Models;

use Brick\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Meteric\Enums\InvoiceState;

/**
 * @property string $id
 * @property string $account_id
 * @property ?string $number
 * @property string $driver
 * @property InvoiceState $state
 * @property string $currency
 * @property int $subtotal_minor
 * @property int $tax_minor
 * @property int $total_minor
 * @property int $paid_minor
 * @property ?CarbonImmutable $due_at
 * @property ?CarbonImmutable $paid_at
 */
class Invoice extends MetericModel
{
    protected $table = 'meteric_invoices';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'state' => InvoiceState::class,
            'subtotal_minor' => 'integer',
            'tax_minor' => 'integer',
            'total_minor' => 'integer',
            'paid_minor' => 'integer',
            'issued_at' => 'immutable_datetime',
            'due_at' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime',
            'version' => 'integer',
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<BillingAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(BillingAccount::class, 'account_id');
    }

    /** @return HasMany<InvoiceLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class, 'invoice_id');
    }

    /** @return HasMany<Charge, $this> */
    public function charges(): HasMany
    {
        return $this->hasMany(Charge::class, 'invoice_id');
    }

    /** @return HasMany<CreditNote, $this> */
    public function creditNotes(): HasMany
    {
        return $this->hasMany(CreditNote::class, 'invoice_id');
    }

    /** @return HasMany<PaymentAllocation, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class, 'invoice_id');
    }

    public function total(): Money
    {
        return Money::ofMinor($this->total_minor, $this->currency);
    }

    public function outstanding(): Money
    {
        return Money::ofMinor(max(0, $this->total_minor - $this->paid_minor), $this->currency);
    }

    public function isPaid(): bool
    {
        return $this->state === InvoiceState::Paid;
    }

    public function isOverdue(): bool
    {
        return ! $this->isPaid()
            && $this->due_at !== null
            && $this->due_at->isPast()
            && $this->state->isIssued();
    }
}
