<?php

declare(strict_types=1);

namespace Meteric\Models;

use Brick\Money\Money;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Meteric\Support\Models;

/**
 * One line of a credit note: part of one invoice line reversed at that line's
 * own tax. `invoice_line_id` is null for a note issued against the invoice as
 * a whole.
 *
 * @property string $credit_note_id
 * @property ?string $invoice_line_id
 * @property ?string $title
 * @property int $net_minor
 * @property int $tax_minor
 * @property float $tax_rate
 * @property int $gross_minor
 */
class CreditNoteLine extends MetericModel
{
    protected string $baseTable = 'credit_note_lines';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'net_minor' => 'integer',
            'tax_minor' => 'integer',
            'tax_rate' => 'float',
            'gross_minor' => 'integer',
            'sort' => 'integer',
        ];
    }

    /** @return BelongsTo<CreditNote, $this> */
    public function creditNote(): BelongsTo
    {
        return $this->belongsTo(Models::for(CreditNote::class), 'credit_note_id');
    }

    /** @return BelongsTo<InvoiceLine, $this> */
    public function invoiceLine(): BelongsTo
    {
        return $this->belongsTo(Models::for(InvoiceLine::class), 'invoice_line_id');
    }

    public function net(): Money
    {
        return Money::ofMinor($this->net_minor, $this->creditNote->currency);
    }

    public function gross(): Money
    {
        return Money::ofMinor($this->gross_minor, $this->creditNote->currency);
    }
}
