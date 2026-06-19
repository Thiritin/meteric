<?php

declare(strict_types=1);

use Brick\Money\Money;
use Ibericode\Vat\Countries;
use Ibericode\Vat\Period;
use Ibericode\Vat\Rates;
use Ibericode\Vat\Validator;
use Meteric\Tax\IbericodeVatResolver;
use Meteric\Tax\TaxContext;

/**
 * Seed ibericode's local cache file (serialized Period[]) so Rates never hits the
 * network. Fresh mtime + long refresh interval keep it offline.
 */
function offlineRates(): Rates
{
    $path = sys_get_temp_dir().'/meteric-vat-'.uniqid().'.cache';
    $data = [
        'DE' => [new Period(new DateTimeImmutable('2020-01-01'), ['standard' => 19.0, 'reduced' => 7.0])],
        'AT' => [new Period(new DateTimeImmutable('2020-01-01'), ['standard' => 20.0])],
    ];
    file_put_contents($path, serialize($data));

    return new Rates($path, refreshInterval: 999999, client: null);
}

function resolver(bool $verify = false): IbericodeVatResolver
{
    return new IbericodeVatResolver(
        rates: offlineRates(),
        validator: new Validator,
        countries: new Countries,
        merchantCountry: 'DE',
        verifyVatId: $verify,
    );
}

it('uses the live (cached) destination rate for B2C', function () {
    $result = resolver()->resolve(Money::of('100.00', 'EUR'), new TaxContext(countryCode: 'DE'));

    expect($result->rate)->toBe(0.19)
        ->and($result->amount->getMinorAmount()->toInt())->toBe(1900);
});

it('reverse-charges cross-border B2B when id is trusted', function () {
    // verifyVatId=false trusts presence (no VIES call) — for offline testing.
    $result = resolver(verify: false)->resolve(Money::of('100.00', 'EUR'), new TaxContext(
        countryCode: 'AT', isBusiness: true, vatId: 'ATU12345678',
    ));

    expect($result->exempt)->toBeTrue()
        ->and($result->amount->getMinorAmount()->toInt())->toBe(0);
});

it('charges VAT for non-EU destinations', function () {
    $result = resolver()->resolve(Money::of('100.00', 'EUR'), new TaxContext(countryCode: 'US'));

    expect($result->exempt)->toBeTrue()
        ->and($result->amount->getMinorAmount()->toInt())->toBe(0);
});
