<?php

declare(strict_types=1);

namespace Meteric\Models;

use Brick\Money\Money;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Meteric\Tax\TaxContext;

/**
 * @property string $id
 * @property ?string $parent_id
 * @property string $owner_type
 * @property string $owner_id
 * @property string $currency
 * @property array $tax_profile
 * @property int $balance_minor
 */
class BillingAccount extends MetericModel
{
    protected $table = 'meteric_billing_accounts';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'tax_profile' => 'array',
            'metadata' => 'array',
            'balance_minor' => 'integer',
        ];
    }

    public function owner(): MorphTo
    {
        return $this->morphTo('owner', 'owner_type', 'owner_id');
    }

    /** @return BelongsTo<self, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<self, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** @return HasMany<Subscription, $this> */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class, 'account_id');
    }

    /** @return HasMany<Invoice, $this> */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'account_id');
    }

    public function creditBalance(): Money
    {
        return Money::ofMinor($this->balance_minor, $this->currency);
    }

    public function applyCredit(Money $amount): void
    {
        $this->increment('balance_minor', $amount->getMinorAmount()->toInt());
    }

    public function taxContext(bool $inclusive = false): TaxContext
    {
        return TaxContext::fromProfile($this->tax_profile ?? [], $inclusive);
    }

    /** Accounts whose charges roll into this payer (self + descendants). */
    public function payerScopeIds(): array
    {
        return [$this->id, ...$this->children()->pluck('id')->all()];
    }
}
