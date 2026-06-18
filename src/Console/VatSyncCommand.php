<?php

declare(strict_types=1);

namespace Billify\Console;

use Billify\Models\TaxRate;
use Carbon\CarbonImmutable;
use Ibericode\Vat\Countries;
use Ibericode\Vat\Rates;
use Illuminate\Console\Command;
use Throwable;

/**
 * Refresh the EU rows of billify_tax_rates from ibericode's live service.
 * Only touches source='ibericode' rows — manual jurisdictions (CH, UK, …) are
 * never modified. When a rate changes, the old row is closed (effective_to) and
 * a new current row is inserted, preserving history.
 */
final class VatSyncCommand extends Command
{
    protected $signature = 'billify:vat-sync {--category=* : Categories to sync (default: standard, reduced)}';

    protected $description = 'Sync EU VAT rates from ibericode into billify_tax_rates';

    public function handle(Rates $rates, Countries $countries): int
    {
        $categories = $this->option('category') ?: ['standard', 'reduced'];
        $today = CarbonImmutable::today();
        $changed = 0;

        foreach ($countries->getCountryCodesInEU() as $country) {
            foreach ($categories as $category) {
                try {
                    $percent = $rates->getRateForCountry($country, $category);
                } catch (Throwable) {
                    continue; // category not defined for this country
                }

                $fraction = number_format($percent / 100, 6, '.', '');
                $current = TaxRate::query()
                    ->where('country', $country)->where('category', $category)
                    ->where('source', 'ibericode')->whereNull('effective_to')
                    ->first();

                if ($current && $current->rate === $fraction) {
                    continue;
                }
                if ($current) {
                    $current->update(['effective_to' => $today]);
                }

                TaxRate::create([
                    'country' => $country, 'category' => $category, 'rate' => $fraction,
                    'effective_from' => $today, 'source' => 'ibericode',
                    'label' => sprintf('VAT %s%% (%s)', round($percent, 2), $country),
                ]);
                $changed++;
            }
        }

        $this->info("billify:vat-sync done — {$changed} rate row(s) written.");

        return self::SUCCESS;
    }
}
