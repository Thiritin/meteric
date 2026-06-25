<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Meteric\Facades\Meteric;
use Meteric\Tax\Vies\Vies;

it('returns a qualified match when details agree', function () {
    Http::fake(['*/check-vat-number' => Http::response([
        'countryCode' => 'DE', 'vatNumber' => '123456789', 'valid' => true, 'requestDate' => '2026-06-25',
        'requestIdentifier' => 'WAPIAAAAXyz', 'name' => 'ACME GmbH', 'address' => 'Strasse 1, Berlin',
        'traderNameMatch' => 'VALID', 'traderStreetMatch' => 'VALID',
    ], 200)]);

    $r = Meteric::viesCheck('DE', '123456789', ['name' => 'ACME GmbH', 'street' => 'Strasse 1']);

    expect($r->valid)->toBeTrue()
        ->and($r->detailsMatch())->toBeTrue()
        ->and($r->name)->toBe('ACME GmbH')
        ->and($r->consultationNumber)->toBe('WAPIAAAAXyz')   // audit reference
        ->and($r->mismatches())->toBe([]);
});

it('flags mismatched company details', function () {
    Http::fake(['*/check-vat-number' => Http::response([
        'countryCode' => 'DE', 'vatNumber' => '123456789', 'valid' => true, 'name' => 'ACME GmbH',
        'traderNameMatch' => 'INVALID', 'traderStreetMatch' => 'VALID',
    ], 200)]);

    $r = Meteric::viesCheck('DE', '123456789', ['name' => 'Wrong Name', 'street' => 'Strasse 1']);

    expect($r->valid)->toBeTrue()
        ->and($r->detailsMatch())->toBeFalse()
        ->and($r->mismatches())->toBe(['name']);
});

it('reports an invalid vat id', function () {
    Http::fake(['*/check-vat-number' => Http::response(['countryCode' => 'DE', 'vatNumber' => '000', 'valid' => false], 200)]);

    $r = Meteric::viesCheck('DE', '000');

    expect($r->valid)->toBeFalse()->and($r->detailsMatch())->toBeFalse();
});

it('uses the configured requester by default and lets a call override it', function () {
    config()->set('meteric.tax.vies_requester', ['country_code' => 'DE', 'vat_number' => 'CFG999']);
    app()->forgetInstance(Vies::class);  // re-resolve with the new config
    Http::fake(['*/check-vat-number' => Http::response(['valid' => true], 200)]);

    Meteric::viesCheck('FR', '111');                                   // no requester -> config default
    Http::assertSent(fn ($req) => ($req['requesterNumber'] ?? null) === 'CFG999');

    Meteric::viesCheck('FR', '222', requester: ['countryCode' => 'AT', 'vatNumber' => 'OVR111']);
    Http::assertSent(fn ($req) => ($req['requesterNumber'] ?? null) === 'OVR111');  // call wins
});

it('sends the trader and requester fields to vies', function () {
    Http::fake(['*/check-vat-number' => Http::response(['valid' => true], 200)]);

    Meteric::viesCheck('DE', '123', ['name' => 'ACME', 'street' => 'S1', 'city' => 'Berlin'], ['countryCode' => 'DE', 'vatNumber' => '999']);

    Http::assertSent(fn ($req) => $req['countryCode'] === 'DE'
        && $req['traderName'] === 'ACME'
        && $req['traderCity'] === 'Berlin'
        && $req['requesterNumber'] === '999');
});
