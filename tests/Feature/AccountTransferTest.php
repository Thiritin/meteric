<?php

declare(strict_types=1);

use Brick\Money\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Meteric\Enums\FirstPeriodPolicy;
use Meteric\Events\AccountTransferred;
use Meteric\Exceptions\AccountNotTransferable;
use Meteric\Facades\Meteric;
use Meteric\Models\BillingAccount;
use Meteric\Models\Charge;
use Meteric\Models\Invoice;
use Meteric\Models\Order;
use Meteric\Models\Payment;
use Meteric\Models\Price;
use Meteric\Models\Product;
use Meteric\Models\Subscription;
use Meteric\Support\Pg;

uses(RefreshDatabase::class);

function transferAccount(string $owner = '1', string $currency = 'EUR'): BillingAccount
{
    return BillingAccount::create(['owner_type' => 'customer', 'owner_id' => $owner, 'currency' => $currency]);
}

function transferPrice(int $minor = 1000, string $currency = 'EUR'): Price
{
    $product = Product::create(['type' => 'vps', 'slug' => 'vps-'.uniqid(), 'name' => 'VPS', 'pricing_model' => 'fixed']);

    return Price::create([
        'product_id' => $product->id, 'currency' => $currency, 'amount_minor' => $minor,
        'pricing_model' => 'fixed', 'interval' => 'month', 'interval_count' => 1,
    ]);
}

/** An account carrying a row in every table a transfer moves. */
function transferrableAccount(string $owner = '1'): BillingAccount
{
    $account = transferAccount($owner);

    Meteric::subscribe()->account($account)->add(transferPrice(1000), 1)->create();

    Meteric::charge($account, Money::ofMinor(5000, 'EUR'), 'Setup fee');
    $invoice = Meteric::invoicePending($account);
    Meteric::recordPayment($invoice, Money::ofMinor(1000, 'EUR'), 'part');

    Meteric::createOrder()
        ->account($account)
        ->firstPeriod(FirstPeriodPolicy::FullPeriod)
        ->add(transferPrice(2000), 1, label: 'web1')
        ->create();

    // Nothing in the package posts to the ledger yet, so the row is written
    // here: the table carries account_id and a transfer that left rows behind
    // would be a bug the day something does post to it.
    DB::table(Pg::table('ledger'))->insert([
        'account_id' => $account->id, 'txn_id' => (string) Str::uuid(), 'entry' => 'opening',
        'debit_minor' => 1000, 'credit_minor' => 0, 'currency' => 'EUR',
    ]);

    return $account;
}

it('moves every table that carries the account, and the frozen customer morph', function () {
    $from = transferrableAccount('1');
    $to = transferAccount('2');

    $moved = Meteric::transferAccount($from, $to);

    expect($moved)->toBe([
        Pg::table('subscriptions') => 1,
        Pg::table('invoices') => 1,
        Pg::table('orders') => 1,
        Pg::table('charges') => 2,
        Pg::table('payments') => 1,
        Pg::table('ledger') => 1,
    ]);

    foreach ([Subscription::class, Invoice::class, Order::class, Charge::class, Payment::class] as $model) {
        expect($model::where('account_id', $from->id)->count())->toBe(0)
            ->and($model::where('account_id', $to->id)->count())->toBeGreaterThan(0);
    }

    expect(DB::table(Pg::table('ledger'))->where('account_id', $to->id)->count())->toBe(1);

    // The morph is what a host app reads to list one customer's invoices, so it
    // has to follow the account or the row is listed under neither customer.
    foreach ([Subscription::class, Invoice::class, Order::class] as $model) {
        $row = $model::where('account_id', $to->id)->first();

        expect($row->customer_type)->toBe('customer')
            ->and((string) $row->customer_id)->toBe('2');
    }
});

it('leaves the invoice itself untouched', function () {
    $from = transferrableAccount('1');
    $to = transferAccount('2');

    $columns = ['id', 'number', 'currency', 'subtotal_minor', 'tax_minor', 'total_minor', 'state', 'issued_at', 'due_at'];
    $before = DB::table(Pg::table('invoices'))->where('account_id', $from->id)->first();
    $lines = Invoice::where('account_id', $from->id)->first()->lines()->count();

    Meteric::transferAccount($from, $to);

    $after = DB::table(Pg::table('invoices'))->where('id', $before->id)->first();

    foreach ($columns as $column) {
        expect($after->{$column})->toBe($before->{$column});
    }

    expect(Invoice::find($before->id)->lines()->count())->toBe($lines);
});

it('fires one event carrying the counts', function () {
    Event::fake([AccountTransferred::class]);

    $from = transferrableAccount('1');
    $to = transferAccount('2');

    Meteric::transferAccount($from, $to);

    Event::assertDispatched(AccountTransferred::class, function (AccountTransferred $event) use ($from, $to): bool {
        return $event->from->id === $from->id
            && $event->to->id === $to->id
            && $event->moved[Pg::table('invoices')] === 1;
    });
});

it('refuses two currencies and moves nothing', function () {
    $from = transferrableAccount('1');
    $to = transferAccount('2', 'CHF');

    expect(fn () => Meteric::transferAccount($from, $to))->toThrow(AccountNotTransferable::class);

    expect(Invoice::where('account_id', $from->id)->count())->toBe(1)
        ->and(Subscription::where('account_id', $from->id)->count())->toBe(1);
});

it('refuses the same account twice', function () {
    $account = transferrableAccount('1');

    expect(fn () => Meteric::transferAccount($account, $account))->toThrow(AccountNotTransferable::class);
});

it('refuses an account that has not been saved', function () {
    $from = transferrableAccount('1');

    expect(fn () => Meteric::transferAccount($from, new BillingAccount))->toThrow(AccountNotTransferable::class);
});

it('refuses a payer that still has sub-accounts', function () {
    $from = transferrableAccount('1');
    $to = transferAccount('2');

    BillingAccount::create([
        'owner_type' => 'customer', 'owner_id' => '3', 'currency' => 'EUR', 'parent_id' => $from->id,
    ]);

    expect(fn () => Meteric::transferAccount($from, $to))->toThrow(AccountNotTransferable::class);

    expect(Invoice::where('account_id', $from->id)->count())->toBe(1);
});
