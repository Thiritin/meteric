<?php

declare(strict_types=1);

use Brick\Money\Money;
use Ibericode\Vat\Countries;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Meteric\Models\TaxRate;
use Meteric\Models\TaxRegistration;
use Meteric\Tax\DatabaseTaxResolver;
use Meteric\Tax\TaxContext;

uses(RefreshDatabase::class);

function dbResolver(): DatabaseTaxResolver
{
    return new DatabaseTaxResolver(new Countries, validator: null, merchantCountry: 'DE');
}

it('charges Swiss VAT once registered in CH', function () {
    TaxRegistration::create(['country' => 'CH', 'scheme' => 'ch_vat', 'number' => 'CHE-123.456.789 MWST']);
    TaxRate::create(['country' => 'CH', 'category' => 'standard', 'rate' => '0.081000', 'effective_from' => '2024-01-01']);

    $result = dbResolver()->resolve(Money::of('100.00', 'CHF'), new TaxContext(countryCode: 'CH'));

    expect($result->rate)->toBe(0.081)
        ->and($result->amount->getMinorAmount()->toInt())->toBe(810);
});

it('uses the lodging category rate when supplied', function () {
    TaxRegistration::create(['country' => 'CH', 'scheme' => 'ch_vat']);
    TaxRate::create(['country' => 'CH', 'category' => 'standard', 'rate' => '0.081000', 'effective_from' => '2024-01-01']);
    TaxRate::create(['country' => 'CH', 'category' => 'lodging', 'rate' => '0.038000', 'effective_from' => '2024-01-01']);

    $result = dbResolver()->resolve(Money::of('100.00', 'CHF'), new TaxContext(countryCode: 'CH', category: 'lodging'));

    expect($result->amount->getMinorAmount()->toInt())->toBe(380);
});

it('applies EU destination rate under OSS registration', function () {
    TaxRegistration::create(['country' => 'EU', 'scheme' => 'eu_oss']);
    TaxRate::create(['country' => 'AT', 'category' => 'standard', 'rate' => '0.200000', 'effective_from' => '2020-01-01']);

    $result = dbResolver()->resolve(Money::of('100.00', 'EUR'), new TaxContext(countryCode: 'AT'));

    expect($result->amount->getMinorAmount()->toInt())->toBe(2000);
});

it('charges nothing where the merchant is not registered', function () {
    TaxRegistration::create(['country' => 'DE', 'scheme' => 'standard']);
    TaxRate::create(['country' => 'DE', 'category' => 'standard', 'rate' => '0.190000', 'effective_from' => '2020-01-01']);

    // Customer in the US — no US registration.
    $result = dbResolver()->resolve(Money::of('100.00', 'USD'), new TaxContext(countryCode: 'US'));

    expect($result->exempt)->toBeTrue()
        ->and($result->amount->getMinorAmount()->toInt())->toBe(0);
});

it('falls back to the standard category when the requested one is missing', function () {
    TaxRegistration::create(['country' => 'DE', 'scheme' => 'standard']);
    TaxRate::create(['country' => 'DE', 'category' => 'standard', 'rate' => '0.190000', 'effective_from' => '2020-01-01']);

    $result = dbResolver()->resolve(Money::of('100.00', 'EUR'), new TaxContext(countryCode: 'DE', category: 'reduced'));

    expect($result->rate)->toBe(0.19);
});

it('charges an exempt buyer nothing in a country the merchant is registered in', function () {
    TaxRegistration::create(['country' => 'DE', 'scheme' => 'domestic']);
    TaxRate::create(['country' => 'DE', 'category' => 'standard', 'rate' => '0.190000', 'effective_from' => '2020-01-01']);

    $result = dbResolver()->resolve(Money::of('100.00', 'EUR'), new TaxContext(
        countryCode: 'DE',
        taxExempt: true,
        exemptReason: 'Diplomatische Mission, § 4 Nr. 7 UStG',
    ));

    expect($result->rate)->toBe(0.0)
        ->and($result->amount->getMinorAmount()->toInt())->toBe(0)
        ->and($result->exempt)->toBeTrue()
        ->and($result->label)->toBe('Diplomatische Mission, § 4 Nr. 7 UStG');
});

it('never labels an exemption as reverse charge', function () {
    TaxRegistration::create(['country' => 'EU', 'scheme' => 'eu_oss']);
    TaxRate::create(['country' => 'AT', 'category' => 'standard', 'rate' => '0.200000', 'effective_from' => '2020-01-01']);

    // A cross-border business with a VAT id: the one context that would be
    // reverse charge if the exemption did not settle it first.
    $result = dbResolver()->resolve(Money::of('100.00', 'EUR'), new TaxContext(
        countryCode: 'AT', isBusiness: true, vatId: 'ATU12345678', taxExempt: true,
    ));

    expect($result->label)->toBe('Tax exempt')
        ->and($result->amount->getMinorAmount()->toInt())->toBe(0);
});

it('carries the exemption through a category switch and a profile', function () {
    $context = (new TaxContext(countryCode: 'DE', taxExempt: true, exemptReason: 'Ruling 4711'))->withCategory('reduced');

    expect($context->taxExempt)->toBeTrue()
        ->and($context->exemptReason)->toBe('Ruling 4711');

    $fromProfile = TaxContext::fromProfile([
        'country' => 'DE', 'tax_exempt' => true, 'tax_exempt_reason' => 'Ruling 4711',
    ]);

    expect($fromProfile->taxExempt)->toBeTrue()
        ->and($fromProfile->exemptReason)->toBe('Ruling 4711');
});
