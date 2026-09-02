<?php

declare(strict_types=1);

namespace Meteric\Models;

use Brick\Money\Money;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Meteric\Enums\CreditState;
use Meteric\Support\Models;

/**
 * @property CreditState $state
 * @property int $amount_minor
 * @property int $tax_minor
 * @property string $currency
 */
class CreditNote extends MetericModel
{
    protected string $baseTable = 'credit_notes';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'state' => CreditState::class,
            'amount_minor' => 'integer',
            'tax_minor' => 'integer',
            'issued_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Models::for(Invoice::class), 'invoice_id');
    }

    /** @return HasMany<CreditNoteLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(Models::for(CreditNoteLine::class), 'credit_note_id')->orderBy('sort');
    }

    /** @return HasMany<Refund, $this> */
    public function refunds(): HasMany
    {
        return $this->hasMany(Models::for(Refund::class), 'credit_note_id');
    }

    public function amount(): Money
    {
        return Money::ofMinor($this->amount_minor, $this->currency);
    }

    public function gross(): Money
    {
        return Money::ofMinor($this->amount_minor + $this->tax_minor, $this->currency);
    }
}
