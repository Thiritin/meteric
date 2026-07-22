<?php

declare(strict_types=1);

namespace Meteric\Usage;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Meteric\Enums\Aggregation;
use Meteric\Enums\BillingMode;
use Meteric\Enums\LineKind;
use Meteric\Models\BillingPeriod;
use Meteric\Models\Charge;
use Meteric\Models\MeterDimension;
use Meteric\Models\SubscriptionItem;
use Meteric\Models\UsageRecord;
use Meteric\Support\Models;
use Meteric\Support\Period;

/**
 * Metered/hourly billing. Usage is reported as UsageRecords, then rolled up at
 * period close into in-arrears Charges (one per dimension), applying the
 * dimension's included allowance and cap. The billing-period guard (keyed by
 * dimension) prevents billing the same window twice.
 */
final class UsageRollup
{
    /** Report usage for a metered dimension. Idempotent on the supplied key. */
    public function record(
        SubscriptionItem $item,
        string $dimensionKey,
        float $quantity,
        ?CarbonImmutable $occurredAt = null,
        ?string $idempotencyKey = null,
        ?string $source = null,
    ): UsageRecord {
        $dimension = $this->dimension($item, $dimensionKey);

        return Models::query(UsageRecord::class)->firstOrCreate(
            ['idempotency_key' => $idempotencyKey ?? (string) Str::uuid()],
            [
                'item_id' => $item->id,
                'dimension_id' => $dimension->id,
                'quantity' => $quantity,
                'occurred_at' => $occurredAt ?? now(),
                'source' => $source,
            ],
        );
    }

    /**
     * Close a usage window for an item: aggregate unbilled records per dimension,
     * bill the overage, and stamp the records as billed.
     *
     * @return list<Charge>
     */
    public function rollup(SubscriptionItem $item, Period $period): array
    {
        $created = [];

        return DB::transaction(function () use ($item, $period, &$created): array {
            // Discover dimensions from the usage itself, not the item's current product —
            // so usage recorded before a plan change (different product) still rolls up.
            $dimensionIds = Models::query(UsageRecord::class)->unbilled()
                ->where('item_id', $item->id)
                ->whereRaw('occurred_at >= ? AND occurred_at < ?', [$period->start, $period->end])
                ->distinct()->pluck('dimension_id');

            foreach ($dimensionIds as $dimensionId) {
                $dimension = Models::query(MeterDimension::class)->findOrFail($dimensionId);
                $records = Models::query(UsageRecord::class)->unbilled()
                    ->where('item_id', $item->id)
                    ->where('dimension_id', $dimension->id)
                    ->whereRaw('occurred_at >= ? AND occurred_at < ?', [$period->start, $period->end])
                    ->orderBy('occurred_at')   // so 'last' aggregation takes the latest report
                    ->get();

                if ($records->isEmpty()) {
                    continue;
                }

                $used = $this->aggregate($records->pluck('quantity')->all(), $dimension->aggregation);
                if (! $this->reserve($item, $dimension->id, $period)) {
                    // Window already billed for this dimension. Attach these
                    // late-arriving records to the existing window charge so they
                    // leave the unbilled pool instead of being re-scanned forever;
                    // the closed window is never billed twice.
                    $existing = Models::query(Charge::class)
                        ->where('origin_id', $item->id)
                        ->where('dimension_id', $dimension->id)
                        ->where('kind', LineKind::Usage->value)
                        ->whereRaw('covers && ?::tstzrange', [$period->toRange()])
                        ->first();
                    if ($existing !== null) {
                        Models::query(UsageRecord::class)->whereIn('id', $records->pluck('id'))->update(['charge_id' => $existing->id]);
                    }

                    continue;
                }

                $amount = $dimension->amountFor($used);
                $charge = Charge::pendingForItem($item, [
                    'dimension_id' => $dimension->id,
                    'kind' => LineKind::Usage,
                    'billing_mode' => BillingMode::InArrears,
                    'description' => sprintf("%s: %s %s\n%s", ucfirst($dimension->key), $this->trim($used), $dimension->unit, $period->label()),
                    'quantity' => $dimension->billedUnits($used),  // blocks when block_size set, else overage units
                    'unit' => $dimension->unit,                    // GB, hours, ...
                    'unit_rate' => $dimension->rate,
                    'amount_minor' => $amount->getMinorAmount()->toInt(),
                    'currency' => $dimension->currency,
                    'covers' => $period,
                    'metadata' => [
                        'dimension' => $dimension->key,
                        'used' => $used,
                        'unit' => $dimension->unit,
                        'overage' => $dimension->overage($used),
                        'block_size' => $dimension->block_size,
                    ],
                    // Deterministic key: a retry of the same window+dimension
                    // collides on the unique index instead of billing a second
                    // charge, backing up the billing-period guard.
                    'idempotency_key' => 'usage_'.substr(hash('sha256', $item->id.$dimension->id.$period->toRange()), 0, 34),
                ]);

                Models::query(UsageRecord::class)->whereIn('id', $records->pluck('id'))->update(['charge_id' => $charge->id]);
                $created[] = $charge;
            }

            return $created;
        });
    }

    private function dimension(SubscriptionItem $item, string $key): MeterDimension
    {
        return Models::query(MeterDimension::class)
            ->where('product_id', $item->product_id)
            ->where('key', $key)
            ->firstOrFail();
    }

    /** Format a usage quantity without trailing zeros: 1500.000000 → "1500". */
    private function trim(float $value): string
    {
        return rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');
    }

    /** @param list<float|string> $values */
    private function aggregate(array $values, Aggregation $how): float
    {
        $nums = array_map('floatval', $values);

        return match ($how) {
            Aggregation::Sum => array_sum($nums),
            Aggregation::Max => $nums === [] ? 0.0 : max($nums),
            Aggregation::Last => $nums === [] ? 0.0 : end($nums),
        };
    }

    private function reserve(SubscriptionItem $item, string $dimensionId, Period $period): bool
    {
        $overlaps = Models::query(BillingPeriod::class)
            ->where('item_id', $item->id)
            ->where('dimension_id', $dimensionId)
            ->whereRaw('covers && ?::tstzrange', [$period->toRange()])
            ->exists();

        if ($overlaps) {
            return false;
        }

        Models::query(BillingPeriod::class)->create(['item_id' => $item->id, 'dimension_id' => $dimensionId, 'covers' => $period]);

        return true;
    }
}
