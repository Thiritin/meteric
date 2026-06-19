<?php

declare(strict_types=1);

namespace Meteric\Usage;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Meteric\Enums\Aggregation;
use Meteric\Enums\BillingMode;
use Meteric\Enums\ChargeState;
use Meteric\Enums\LineKind;
use Meteric\Models\BillingPeriod;
use Meteric\Models\Charge;
use Meteric\Models\MeterDimension;
use Meteric\Models\SubscriptionItem;
use Meteric\Models\UsageRecord;
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

        return UsageRecord::firstOrCreate(
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
        $sub = $item->subscription;
        $created = [];

        return DB::transaction(function () use ($item, $sub, $period, &$created): array {
            // Discover dimensions from the usage itself, not the item's current product —
            // so usage recorded before a plan change (different product) still rolls up.
            $dimensionIds = UsageRecord::query()->unbilled()
                ->where('item_id', $item->id)
                ->whereRaw('occurred_at >= ? AND occurred_at < ?', [$period->start, $period->end])
                ->distinct()->pluck('dimension_id');

            foreach ($dimensionIds as $dimensionId) {
                $dimension = MeterDimension::findOrFail($dimensionId);
                $records = UsageRecord::query()->unbilled()
                    ->where('item_id', $item->id)
                    ->where('dimension_id', $dimension->id)
                    ->whereRaw('occurred_at >= ? AND occurred_at < ?', [$period->start, $period->end])
                    ->get();

                if ($records->isEmpty()) {
                    continue;
                }

                $used = $this->aggregate($records->pluck('quantity')->all(), $dimension->aggregation);
                if (! $this->reserve($item, $dimension->id, $period)) {
                    continue; // window already billed for this dimension
                }

                $amount = $dimension->amountFor($used);
                $charge = Charge::create([
                    'account_id' => $sub->account_id,
                    'subscription_id' => $sub->id,
                    'origin_type' => 'subscription_item',
                    'origin_id' => $item->id,
                    'dimension_id' => $dimension->id,
                    'kind' => LineKind::Usage,
                    'billing_mode' => BillingMode::InArrears,
                    'state' => ChargeState::Pending,
                    'description' => "{$dimension->key} usage",
                    'quantity' => $dimension->billableQuantity($used),
                    'unit_rate' => $dimension->rate,
                    'amount_minor' => $amount->getMinorAmount()->toInt(),
                    'currency' => $dimension->currency,
                    'covers' => $period,
                    'idempotency_key' => 'usage_'.Str::uuid()->toString(),
                ]);

                UsageRecord::whereIn('id', $records->pluck('id'))->update(['charge_id' => $charge->id]);
                $created[] = $charge;
            }

            return $created;
        });
    }

    private function dimension(SubscriptionItem $item, string $key): MeterDimension
    {
        return MeterDimension::query()
            ->where('product_id', $item->product_id)
            ->where('key', $key)
            ->firstOrFail();
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
        $overlaps = BillingPeriod::query()
            ->where('item_id', $item->id)
            ->where('dimension_id', $dimensionId)
            ->whereRaw('covers && ?::tstzrange', [$period->toRange()])
            ->exists();

        if ($overlaps) {
            return false;
        }

        BillingPeriod::create(['item_id' => $item->id, 'dimension_id' => $dimensionId, 'covers' => $period]);

        return true;
    }
}
