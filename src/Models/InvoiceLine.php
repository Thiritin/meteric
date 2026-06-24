<?php

declare(strict_types=1);

namespace Meteric\Models;

use Brick\Money\Money;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Meteric\Casts\MoneyCast;
use Meteric\Casts\PeriodCast;
use Meteric\Enums\LineKind;
use Meteric\Support\Period;

/**
 * @property LineKind $kind
 * @property ?string $title
 * @property ?string $group
 * @property ?string $description
 * @property ?string $unit
 * @property float $quantity
 * @property Money $amount
 * @property int $amount_minor
 * @property float $tax_rate
 * @property int $tax_minor
 * @property string $currency
 * @property ?Period $covers
 * @property array $metadata
 */
class InvoiceLine extends MetericModel
{
    protected string $baseTable = 'invoice_lines';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'kind' => LineKind::class,
            'quantity' => 'float',
            'unit_minor' => 'integer',
            'unit_rate' => 'string',
            'amount_minor' => 'integer',
            'amount' => MoneyCast::class.':amount_minor,currency',
            'tax_rate' => 'float',
            'tax_minor' => 'integer',
            'covers' => PeriodCast::class,
            'sort' => 'integer',
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    /** @return BelongsTo<Charge, $this> */
    public function charge(): BelongsTo
    {
        return $this->belongsTo(Charge::class, 'charge_id');
    }

    public function gross(): Money
    {
        return Money::ofMinor($this->amount_minor + $this->tax_minor, $this->currency);
    }

    /** The service period as a string, e.g. "2026-06-01 to 2026-06-30" (inclusive end). Null for one-off lines. */
    public function coversLabel(string $format = 'Y-m-d'): ?string
    {
        return $this->covers?->label($format);
    }

    /**
     * For usage lines: the total consumed and its unit, e.g. "1500 GB".
     * Reads the rollup metadata; null when not a usage line.
     */
    public function usedSummary(): ?string
    {
        $used = $this->metadata['used'] ?? null;
        if ($used === null) {
            return null;
        }

        return rtrim(rtrim(number_format((float) $used, 2, '.', ''), '0'), '.').' '.($this->unit ?? '');
    }
}
