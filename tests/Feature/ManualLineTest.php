<?php

declare(strict_types=1);

use Brick\Money\Context\CustomContext;
use Brick\Money\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Meteric\Enums\LineKind;
use Meteric\Facades\Meteric;
use Meteric\Invoicing\ManualLine;
use Meteric\Models\BillingAccount;

uses(RefreshDatabase::class);

function typedAccount(string $country = 'DE'): BillingAccount
{
    return BillingAccount::create([
        'owner_type' => 'user',
        'owner_id' => '1',
        'currency' => 'EUR',
        'tax_profile' => ['country' => $country, 'merchant_country' => 'DE', 'name' => 'Acme GmbH'],
    ]);
}

it('prices a line from its quantity and unit price', function () {
    $draft = Meteric::createInvoice(typedAccount(), 'EUR');

    $line = Meteric::addManualLine($draft, new ManualLine(
        title: 'Consulting',
        quantity: 3.0,
        unit: 'hour',
        unitPrice: Money::of('80.00', 'EUR'),
    ));

    expect($line->amount_minor)->toBe(24000)
        ->and($line->quantity)->toBe(3.0)
        ->and($line->unit)->toBe('hour')
        ->and($line->unit_minor)->toBe(8000);
});

it('takes a per line discount off the line total', function () {
    $draft = Meteric::createInvoice(typedAccount(), 'EUR');

    $line = Meteric::addManualLine($draft, new ManualLine(
        title: 'Consulting',
        quantity: 2.0,
        unitPrice: Money::of('100.00', 'EUR'),
        discountPercent: 10.0,
    ));

    expect($line->amount_minor)->toBe(18000);
});

it('rounds the line once rather than the unit price first', function () {
    $draft = Meteric::createInvoice(typedAccount(), 'EUR');

    // 3 x 10.005 is 30.015, which rounds to 30.02. Rounding the unit price
    // first would give 3 x 10.01 = 30.03, and the line would disagree with the
    // unit price printed beside it.
    $line = Meteric::addManualLine($draft, new ManualLine(
        title: 'Parts',
        quantity: 3.0,
        unitPrice: Money::of('10.005', 'EUR', new CustomContext(3)),
    ));

    expect($line->amount_minor)->toBe(3002)
        // Stored in cents, which is what a document prints.
        ->and($line->unit_minor)->toBe(1001);
});

it('reads a gross price as the same intent as the net one', function () {
    $net = Meteric::createInvoice(typedAccount(), 'EUR');
    $gross = Meteric::createInvoice(typedAccount(), 'EUR');

    $netLine = Meteric::addManualLine($net, new ManualLine(
        title: 'Consulting',
        quantity: 1.0,
        unitPrice: Money::of('100.00', 'EUR'),
    ));

    $grossLine = Meteric::addManualLine($gross, new ManualLine(
        title: 'Consulting',
        quantity: 1.0,
        unitPrice: Money::of('119.00', 'EUR'),
        priceIsGross: true,
    ));

    // 119.00 gross at 19 per cent is 100.00 net, so the two documents say the
    // same thing.
    expect($grossLine->amount_minor)->toBe($netLine->amount_minor)
        ->and($grossLine->tax_minor)->toBe($netLine->tax_minor);
});

it('writes a text line that carries no money and enters no total', function () {
    $draft = Meteric::createInvoice(typedAccount(), 'EUR');

    Meteric::addManualLine($draft, new ManualLine(
        title: 'Consulting',
        quantity: 1.0,
        unitPrice: Money::of('100.00', 'EUR'),
    ));

    $text = Meteric::addManualLine($draft, ManualLine::text('Everything below is covered by the retainer.'));

    $draft->refresh();

    expect($text->kind)->toBe(LineKind::Text)
        ->and($text->amount_minor)->toBe(0)
        ->and($text->tax_minor)->toBe(0)
        ->and($draft->subtotal_minor)->toBe(10000);
});

it('refuses a line on an invoice that is no longer a draft', function () {
    $draft = Meteric::createInvoice(typedAccount(), 'EUR');

    Meteric::addManualLine($draft, new ManualLine(
        title: 'Consulting',
        quantity: 1.0,
        unitPrice: Money::of('100.00', 'EUR'),
    ));

    $issued = Meteric::finalizeInvoice($draft);

    expect(fn () => Meteric::addManualLine($issued, ManualLine::text('Too late')))
        ->toThrow(LogicException::class);
});
