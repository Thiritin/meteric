<?php

declare(strict_types=1);

use Brick\Money\Money;
use Meteric\Tax\EuVatResolver;
use Meteric\Tax\TaxContext;

it('applies the destination rate for B2C in an EU country', function () {
    $resolver = new EuVatResolver(merchantCountry: 'DE');
    $result = $resolver->resolve(Money::of('100.00', 'EUR'), new TaxContext(countryCode: 'DE'));

    expect($result->rate)->toBe(0.19)
        ->and($result->amount->getMinorAmount()->toInt())->toBe(1900);
});

it('reverse-charges cross-border B2B with a VAT id', function () {
    $resolver = new EuVatResolver(merchantCountry: 'DE');
    $result = $resolver->resolve(Money::of('100.00', 'EUR'), new TaxContext(
        countryCode: 'AT', isBusiness: true, vatId: 'ATU12345678',
    ));

    expect($result->exempt)->toBeTrue()
        ->and($result->amount->getMinorAmount()->toInt())->toBe(0)
        ->and($result->label)->toContain('Reverse charge');
});

it('charges domestic B2B normally (no reverse charge same country)', function () {
    $resolver = new EuVatResolver(merchantCountry: 'DE');
    $result = $resolver->resolve(Money::of('100.00', 'EUR'), new TaxContext(
        countryCode: 'DE', isBusiness: true, vatId: 'DE123456789',
    ));

    expect($result->rate)->toBe(0.19);
});

it('applies no VAT for non-EU destinations', function () {
    $resolver = new EuVatResolver(merchantCountry: 'DE');
    $result = $resolver->resolve(Money::of('100.00', 'EUR'), new TaxContext(countryCode: 'US'));

    expect($result->exempt)->toBeTrue()
        ->and($result->amount->getMinorAmount()->toInt())->toBe(0);
});

it('back-calculates tax for inclusive prices', function () {
    $resolver = new EuVatResolver(merchantCountry: 'DE');
    // €119 inclusive at 19% -> €19 tax
    $result = $resolver->resolve(Money::of('119.00', 'EUR'), new TaxContext(countryCode: 'DE', taxInclusive: true));

    expect($result->amount->getMinorAmount()->toInt())->toBe(1900);
});
