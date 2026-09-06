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
use Meteric\Tax\TaxContext;

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
 * @property ?CarbonImmutable $issued_at
 * @property ?CarbonImmutable $due_at
 * @property ?CarbonImmutable $overdue_at
 * @property ?CarbonImmutable $paid_at
 * @property int $version
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
            'tax_profile' => 'array',
        ];
    }

    /**
     * The tax profile this invoice was priced under, or the account's current
     * one for an invoice issued before the snapshot existed.
     *
     * **Read this, not `$invoice->account->tax_profile`.** The account moves
     * with the customer; the invoice is a document that was already sent. An
     * empty array means neither was ever recorded.
     *
     * @return array<string,mixed>
     */
    public function taxProfile(): array
    {
        return $this->tax_profile ?? $this->account?->tax_profile ?? [];
    }

    /** The tax context this invoice was priced under. */
    public function taxContext(bool $inclusive = false): TaxContext
    {
        return TaxContext::fromProfile($this->taxProfile(), $inclusive);
    }

    /**
     * Record the profile the lines are being priced under. A no-op once the
     * invoice has left draft, where the database refuses the write anyway: the
     * seal is the trigger, this only keeps a caller from meeting it.
     *
     * @param  array<string,mixed>  $profile
     */
    public function recordTaxProfile(array $profile): void
    {
        // Loose comparison on purpose: jsonb hands the keys back in its own
        // order, so an identical profile would otherwise be rewritten on every
        // line.
        if ($this->state !== InvoiceState::Draft || $this->tax_profile == $profile) {
            return;
        }

        $this->forceFill(['tax_profile' => $profile])->save();
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
     * Not a relationship — it executes a query and returns the set.
     *
     * @return Collection<int, Charge>
     */
    public function billedCharges(): Collection
    {
        $ids = $this->lines()->whereNotNull('charge_id')->distinct()->pluck('charge_id');

        return Models::query(Charge::class)->whereIn('id', $ids)->get();
    }

    /**
     * @deprecated Use billedCharges(); the relation-style name misleads.
     *
     * @return Collection<int, Charge>
     */
    public function charges(): Collection
    {
        return $this->billedCharges();
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
     * listener to resume the right services after payment. Not a relationship —
     * it executes a query and returns the set.
     *
     * @return Collection<int, Subscription>
     */
    public function billedSubscriptions(): Collection
    {
        $chargeIds = $this->lines()->whereNotNull('charge_id')->distinct()->pluck('charge_id');
        $ids = Models::query(Charge::class)->whereIn('id', $chargeIds)->whereNotNull('subscription_id')->distinct()->pluck('subscription_id');

        return Models::query(Subscription::class)->whereIn('id', $ids)->get();
    }

    /**
     * @deprecated Use billedSubscriptions(); the relation-style name misleads.
     *
     * @return Collection<int, Subscription>
     */
    public function subscriptions(): Collection
    {
        return $this->billedSubscriptions();
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
