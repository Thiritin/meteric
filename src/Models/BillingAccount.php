<?php

declare(strict_types=1);

namespace Meteric\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\DB;
use Meteric\Support\Models;
use Meteric\Tax\TaxContext;

/**
 * @property string $id
 * @property ?string $parent_id
 * @property string $owner_type
 * @property string $owner_id
 * @property string $currency
 * @property array $tax_profile
 */
class BillingAccount extends MetericModel
{
    protected string $baseTable = 'billing_accounts';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'tax_profile' => 'array',
            'metadata' => 'array',
        ];
    }

    public function owner(): MorphTo
    {
        return $this->morphTo('owner', 'owner_type', 'owner_id');
    }

    /** @return BelongsTo<self, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Models::for(self::class), 'parent_id');
    }

    /** @return HasMany<self, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(Models::for(self::class), 'parent_id');
    }

    /** @return HasMany<Subscription, $this> */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Models::for(Subscription::class), 'account_id');
    }

    /** @return HasMany<Invoice, $this> */
    public function invoices(): HasMany
    {
        return $this->hasMany(Models::for(Invoice::class), 'account_id');
    }

    public function taxContext(bool $inclusive = false): TaxContext
    {
        return TaxContext::fromProfile($this->tax_profile ?? [], $inclusive);
    }

    /**
     * Accounts whose charges roll into this payer: self and every descendant at
     * any depth (a reseller's customers and their sub-accounts). A recursive CTE
     * walks the whole tree so a consolidated invoice never silently drops a
     * grandchild account.
     *
     * @return list<string>
     */
    public function payerScopeIds(): array
    {
        $table = $this->getTable();

        $rows = DB::select(
            "WITH RECURSIVE meteric_payer_tree AS (
                SELECT id, parent_id FROM {$table} WHERE id = ?
                UNION
                SELECT c.id, c.parent_id FROM {$table} c
                JOIN meteric_payer_tree t ON c.parent_id = t.id
            )
            SELECT id FROM meteric_payer_tree",
            [$this->id],
        );

        return array_map(static fn ($row): string => (string) $row->id, $rows);
    }
}
