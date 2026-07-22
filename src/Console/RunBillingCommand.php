<?php

declare(strict_types=1);

namespace Meteric\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Meteric\Contracts\Clock;
use Meteric\Meteric;
use Meteric\Models\BillingAccount;
use Meteric\Models\Subscription;
use Meteric\Subscriptions\OrderManager;

/**
 * The billing tick. For every subscription whose period has ended it rolls up the
 * elapsed usage window into charges and renews (accrues the next cycle), then
 * issues an invoice per affected account and flags any past-due invoices overdue.
 *
 * Every step is idempotent (billing-period guard, overdue_at guard), so schedule
 * this on a short interval and it only acts when there is something to do.
 */
final class RunBillingCommand extends Command
{
    protected $signature = 'meteric:run';

    protected $description = 'Billing tick: roll up usage, renew, invoice, flag overdue';

    public function handle(Meteric $meteric, Clock $clock): int
    {
        $at = $clock->now();

        $rolled = 0;
        $renewed = 0;
        $failed = 0;
        $accountIds = [];

        Subscription::query()->dueForRenewal($at)->with('items')->cursor()->each(
            function (Subscription $sub) use ($meteric, $at, &$rolled, &$renewed, &$failed, &$accountIds): void {
                // Isolate each subscription: one that throws (bad data, driver
                // hiccup) must not abort the tick and strand every later one.
                try {
                    foreach ($sub->items as $item) {
                        $item->setRelation('subscription', $sub);
                        if ($item->current_period !== null) {
                            $rolled += count($meteric->rollupUsage($item, $item->current_period));
                        }
                    }
                    $renewed += count($meteric->renew($sub, $at));
                    $accountIds[$sub->account_id] = $sub->account_id;
                } catch (\Throwable $e) {
                    $failed++;
                    Log::error('meteric:run subscription failed', [
                        'subscription_id' => $sub->id,
                        'exception' => $e->getMessage(),
                    ]);
                }
            }
        );

        $invoiced = 0;
        foreach ($accountIds as $id) {
            $account = BillingAccount::find($id);
            if ($account === null) {
                continue;
            }
            try {
                $invoiced += count($meteric->invoiceAllPending($account));
            } catch (\Throwable $e) {
                $failed++;
                Log::error('meteric:run invoicing failed', [
                    'account_id' => $id,
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        $canceled = $meteric->processDueCancellations($at);
        $overdue = $meteric->markOverdue();
        $expired = app(OrderManager::class)->expireDue($at);

        $this->info("meteric:run done: {$rolled} usage + {$renewed} renewal charge(s), {$invoiced} invoice(s), {$canceled} canceled, {$overdue} newly overdue, {$expired} order(s) expired.");

        if ($failed > 0) {
            $this->warn("meteric:run: {$failed} subscription/account(s) failed and were skipped (see logs).");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
