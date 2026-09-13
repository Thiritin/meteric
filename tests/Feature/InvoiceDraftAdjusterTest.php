<?php

declare(strict_types=1);

use Brick\Money\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Meteric\Contracts\InvoiceDraftAdjuster;
use Meteric\Facades\Meteric;
use Meteric\Invoicing\InvoiceManager;
use Meteric\Models\BillingAccount;
use Meteric\Models\Charge;

uses(RefreshDatabase::class);

/**
 * The window an application gets on a document the engine raises: the billing
 * run calls invoicePending from inside the package, so without this there is no
 * point at which an application can put its own claim on that invoice.
 */
function adjuster(callable $adjust): void
{
    app()->singleton(InvoiceDraftAdjuster::class, fn (): InvoiceDraftAdjuster => new class($adjust) implements InvoiceDraftAdjuster
    {
        public function __construct(private $adjust) {}

        public function adjust(BillingAccount $account, string $currency, Collection $charges): iterable
        {
            return ($this->adjust)($account, $currency, $charges);
        }
    });

    app()->forgetInstance(InvoiceManager::class);
    app()->forgetInstance(\Meteric\Meteric::class);
}

function adjusterAccount(): BillingAccount
{
    return BillingAccount::create(['owner_type' => 'user', 'owner_id' => '1', 'currency' => 'EUR']);
}

it('bills nothing extra when no adjuster is bound', function () {
    $account = adjusterAccount();
    Meteric::charge($account, Money::ofMinor(5000, 'EUR'), 'Webspace');

    $invoice = Meteric::invoicePending($account);

    expect($invoice->subtotal_minor)->toBe(5000)
        ->and($invoice->lines()->count())->toBe(1);
});

it('bills a charge the adjuster adds on the same document', function () {
    $account = adjusterAccount();

    adjuster(fn (BillingAccount $a, string $currency, Collection $charges): array => [
        Meteric::charge($a, Money::ofMinor(-2000, $currency), 'Goodwill'),
    ]);

    Meteric::charge($account, Money::ofMinor(5000, 'EUR'), 'Webspace');

    $invoice = Meteric::invoicePending($account);

    expect($invoice->subtotal_minor)->toBe(3000)
        ->and($invoice->lines()->count())->toBe(2)
        ->and(Charge::query()->pending()->count())->toBe(0);
});

it('sees what is about to be billed', function () {
    $account = adjusterAccount();
    $seen = null;

    adjuster(function (BillingAccount $a, string $currency, Collection $charges) use (&$seen): array {
        $seen = [$currency, $charges->sum('amount_minor'), $charges->count()];

        return [];
    });

    Meteric::charge($account, Money::ofMinor(5000, 'EUR'), 'Webspace');
    Meteric::charge($account, Money::ofMinor(1500, 'EUR'), 'Backups');

    Meteric::invoicePending($account);

    expect($seen)->toBe(['EUR', 6500, 2]);
});

it('holds the invoice when what the adjuster added outweighs the charges', function () {
    $account = adjusterAccount();

    adjuster(fn (BillingAccount $a, string $currency, Collection $charges): array => [
        Meteric::charge($a, Money::ofMinor(-9000, $currency), 'Goodwill'),
    ]);

    Meteric::charge($account, Money::ofMinor(5000, 'EUR'), 'Webspace');

    expect(Meteric::invoicePending($account))->toBeNull()
        ->and(Charge::query()->pending()->count())->toBe(2);
});

it('refuses a charge that belongs to another account', function () {
    $account = adjusterAccount();
    $other = BillingAccount::create(['owner_type' => 'user', 'owner_id' => '2', 'currency' => 'EUR']);

    adjuster(fn (BillingAccount $a, string $currency, Collection $charges): array => [
        Meteric::charge($other, Money::ofMinor(-1000, $currency), 'Somebody else'),
    ]);

    Meteric::charge($account, Money::ofMinor(5000, 'EUR'), 'Webspace');

    expect(fn () => Meteric::invoicePending($account))->toThrow(LogicException::class);
});

it('refuses a charge in another currency', function () {
    $account = adjusterAccount();

    adjuster(fn (BillingAccount $a, string $currency, Collection $charges): array => [
        Meteric::charge($a, Money::ofMinor(-1000, 'USD'), 'Wrong currency'),
    ]);

    Meteric::charge($account, Money::ofMinor(5000, 'EUR'), 'Webspace');

    expect(fn () => Meteric::invoicePending($account))->toThrow(LogicException::class);
});
