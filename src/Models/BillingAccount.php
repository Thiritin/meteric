<?php

declare(strict_types=1);

namespace Meteric\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\DB;
use Meteric\Contracts\Clock;
use Meteric\Enums\InvoiceSchedule;
use Meteric\Invoicing\CollectionCycle;
use Meteric\Support\Models;
use Meteric\Tax\TaxContext;

/**
 * @property string $id
 * @property ?string $parent_id
 * @property string $owner_type
 * @property string $owner_id
 * @property string $currency
 * @property array $tax_profile
 * @property InvoiceSchedule $invoice_schedule
 * @property ?int $invoice_day
 * @property ?CarbonImmutable $collected_through
 */
class BillingAccount extends MetericModel
{
    protected string $baseTable = 'billing_accounts';

    protected $guarded = [];

    /** So a freshly made account answers about its schedule before it is reloaded. */
    protected $attributes = ['invoice_schedule' => 'immediate'];

    protected function casts(): array
    {
        return [
            'tax_profile' => 'array',
            'metadata' => 'array',
            'invoice_schedule' => InvoiceSchedule::class,
            'collected_through' => 'immutable_date',
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

    /**
     * Whether invoicing this account's pending charges waits for its collection
     * date. Read by every path that would otherwise issue a document on the
     * spot; the charges themselves accrue either way.
     */
    public function defersInvoicing(): bool
    {
        return ($this->invoice_schedule ?? InvoiceSchedule::Immediate)->defersInvoicing();
    }

    /** The day of the month this account's collective invoice is issued on. */
    public function collectionDay(): int
    {
        return $this->invoice_day ?? (int) config('meteric.invoice.collection_day', 1);
    }

    /** The most recent collection boundary on or before $at. */
    public function collectionBoundary(?CarbonImmutable $at = null): CarbonImmutable
    {
        return CollectionCycle::boundary($at ?? app(Clock::class)->now(), $this->collectionDay());
    }

    /** The next collection boundary after $at: when this account bills again. */
    public function nextCollectionAt(?CarbonImmutable $at = null): CarbonImmutable
    {
        return CollectionCycle::next($at ?? app(Clock::class)->now(), $this->collectionDay());
    }

    /**
     * Whether this account's collection date has come round again since it was
     * last billed. `collected_through` is the boundary already billed, so an
     * account opted in part way through a cycle is stamped rather than billed
     * and joins the run at its first whole boundary. A null stamp is an account
     * put on the schedule by hand: the first run anchors it, and bills whatever
     * it happened to be holding.
     */
    public function isDueForCollection(?CarbonImmutable $at = null): bool
    {
        if (! $this->defersInvoicing()) {
            return false;
        }

        return $this->collected_through === null
            || $this->collected_through->lessThan($this->collectionBoundary($at));
    }

    /**
     * Candidates for a collection run. The exact boundary is per account, since
     * the day is, so this narrows in SQL and `isDueForCollection` decides: a row
     * whose stamp is not older than today cannot be due whatever its day is.
     *
     * @param  Builder<self>  $query
     */
    public function scopeDueForCollection(Builder $query, CarbonImmutable $at): void
    {
        $query->where('invoice_schedule', InvoiceSchedule::Collective->value)
            ->where(function (Builder $q) use ($at): void {
                $q->whereNull('collected_through')->orWhere('collected_through', '<', $at->toDateString());
            })
            ->orderByRaw('collected_through nulls first')
            ->orderBy('id');
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
