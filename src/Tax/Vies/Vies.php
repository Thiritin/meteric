<?php

declare(strict_types=1);

namespace Meteric\Tax\Vies;

use Illuminate\Support\Facades\Http;

/**
 * Qualified VIES (VAT Information Exchange System) client. Calls the EU VIES REST
 * API, optionally passing the trader's name and address so VIES returns per-field
 * match flags. The boolean reverse-charge check the tax resolvers run is separate;
 * this is for the "company details do not match" warning and the audit record.
 */
final class Vies
{
    /** @param  array<string,string>  $requester  default requester (countryCode, vatNumber) from config */
    public function __construct(
        private string $baseUrl = 'https://ec.europa.eu/taxation_customs/vies/rest-api',
        private array $requester = [],
    ) {}

    /**
     * @param  array<string,string>  $trader  name, companyType, street, postalCode, city
     * @param  array<string,string>  $requester  your own VAT id (countryCode, vatNumber) for the consultation number; falls back to config
     */
    public function check(string $countryCode, string $vatNumber, array $trader = [], array $requester = []): ViesResult
    {
        $requester = array_merge($this->requester, $requester);

        $body = array_filter([
            'countryCode' => $countryCode,
            'vatNumber' => $vatNumber,
            'requesterMemberStateCode' => $requester['countryCode'] ?? null,
            'requesterNumber' => $requester['vatNumber'] ?? null,
            'traderName' => $trader['name'] ?? null,
            'traderCompanyType' => $trader['companyType'] ?? null,
            'traderStreet' => $trader['street'] ?? null,
            'traderPostalCode' => $trader['postalCode'] ?? null,
            'traderCity' => $trader['city'] ?? null,
        ], fn ($v): bool => $v !== null && $v !== '');

        $res = Http::acceptJson()->post($this->baseUrl.'/check-vat-number', $body)->throw()->json();

        return new ViesResult(
            valid: (bool) ($res['valid'] ?? false),
            countryCode: (string) ($res['countryCode'] ?? $countryCode),
            vatNumber: (string) ($res['vatNumber'] ?? $vatNumber),
            requestDate: $res['requestDate'] ?? null,
            consultationNumber: $res['requestIdentifier'] ?? null,
            name: $res['name'] ?? null,
            address: $res['address'] ?? null,
            matches: array_filter([
                'name' => $res['traderNameMatch'] ?? null,
                'companyType' => $res['traderCompanyTypeMatch'] ?? null,
                'street' => $res['traderStreetMatch'] ?? null,
                'postalCode' => $res['traderPostalCodeMatch'] ?? null,
                'city' => $res['traderCityMatch'] ?? null,
            ], fn ($v): bool => $v !== null),
        );
    }
}
