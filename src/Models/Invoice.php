<?php

declare(strict_types=1);

namespace Meteric\Models;

use Brick\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Meteric\Enums\InvoiceState;
use Meteric\Support\Models;

/**
 * @property string $id
 * @property string $account_id
 * @property ?string $customer_type
 * @property ?string $customer_id
 * @property ?string $number
 * @property string $driver
 * @property ?array $metadata
 * @property ?string $external_id
 * @property ?string $external_url
 * @property InvoiceState $state
 * @property string $currency
 * @property int $subtotal_minor
 * @property int $tax_minor
 * @property int $total_minor
 * @property int $paid_minor
 * @property ?CarbonImmutable $due_at
 * @property ?CarbonImmutable $overdue_at
 * @property ?CarbonImmutable $paid_at
 */
class Invoice extends MetericModel
{
    protected string $baseTable = 'invoices';

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
            'overdue_at' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime',
            'version' => 'integer',
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<BillingAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Models::for(BillingAccount::class), 'account_id');
    }

    /** @return HasMany<InvoiceLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(Models::for(InvoiceLine::class), 'invoice_id');
    }

    /**
     * The charges this invoice bills, derived through its lines (a charge is
     * linked only via invoice_lines.charge_id). Manual lines carry no charge.
     *
     * @return Collection<int, Charge>
     */
    public function charges(): Collection
    {
        $ids = $this->lines()->whereNotNull('charge_id')->distinct()->pluck('charge_id');

        return Models::query(Charge::class)->whereIn('id', $ids)->get();
    }

    /** @return HasMany<CreditNote, $this> */
    public function creditNotes(): HasMany
    {
        return $this->hasMany(Models::for(CreditNote::class), 'invoice_id');
    }

    /** @return HasMany<PaymentAllocation, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Models::for(PaymentAllocation::class), 'invoice_id');
    }

    /**
     * Subscriptions this invoice bills, via its charges. Use in an InvoicePaid
     * listener to resume the right services after payment.
     *
     * @return Collection<int, Subscription>
     */
    public function subscriptions(): Collection
    {
        $chargeIds = $this->lines()->whereNotNull('charge_id')->distinct()->pluck('charge_id');
        $ids = Models::query(Charge::class)->whereIn('id', $chargeIds)->whereNotNull('subscription_id')->distinct()->pluck('subscription_id');

        return Models::query(Subscription::class)->whereIn('id', $ids)->get();
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
