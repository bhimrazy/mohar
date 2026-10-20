<?php

namespace App\Modules\Storefront\Services;

use App\Contracts\Billing\TaxCalculator;
use App\Data\Billing\InvoiceLineData;
use App\Enums\BillableType;

/**
 * How prices read on public pages in one currency (spec §5.4): with or without tax, and the tax statement shown under
 * every price group and in the footer. Each currency is priced for its own market (`storefront.tax_markets`: NPR for
 * Nepal, INR for India), and the rates come from Billing's tax rules for that country: "+13% VAT at checkout" for NPR,
 * "+18% GST at checkout" for INR. A currency sold across markets (USD) gets the neutral statement. Showing prices with
 * tax is a per-currency choice, off by default; when Billing already prices tax-inclusive, amounts are shown as they
 * are. Until the TaxDisplay contract (spec K6) exists this probes TaxCalculator with one taxable line.
 */
class PriceDisplay
{
    private const BASE = 1_000_000;

    public const NEUTRAL_NOTE = 'Tax added at checkout where applicable';

    /**
     * @param  array<string, mixed>  $content  normalised site content
     * @return array{multiplier: int, note: string, inclusive: bool}
     */
    public function forCurrency(string $currency, array $content): array
    {
        [$taxes, $total] = $this->taxes($currency);
        // Customers know the market's tax by its family name (GST, whether Billing applies IGST or CGST + SGST); a
        // market without one in storefront.tax_families shows Billing's rule names.
        $family = config('storefront.tax_families.'.config('storefront.tax_markets.'.strtoupper($currency)));
        $label = is_string($family) && $family !== ''
            ? $this->percent(array_sum(array_column($taxes, 'rate'))).' '.$family
            : implode(' + ', array_map(fn (array $tax): string => $this->percent($tax['rate']).' '.$tax['name'], $taxes));
        $billingInclusive = $taxes !== [] && $total === self::BASE;
        $wantsInclusive = in_array($currency, $content['prices']['tax_inclusive'], true);
        $inclusive = $taxes !== [] && ($billingInclusive || $wantsInclusive);

        $note = $content['prices']['notes'][$currency] ?? null;
        $note ??= match (true) {
            $taxes === [] => self::NEUTRAL_NOTE,
            $inclusive => "Prices include {$label}",
            default => "+{$label} at checkout",
        };

        return [
            'multiplier' => $inclusive && ! $billingInclusive ? $total : self::BASE,
            'note' => $note,
            'inclusive' => $inclusive,
        ];
    }

    /**
     * An amount as visitors see it under the display (minor units, rounded half up).
     *
     * @param  array{multiplier: int}  $display
     */
    public static function apply(int $amount, array $display): int
    {
        return intdiv($amount * $display['multiplier'] + intdiv(self::BASE, 2), self::BASE);
    }

    /**
     * @return array{0: list<array{name: string, rate: float}>, 1: int} taxes that apply, and the total on BASE
     */
    private function taxes(string $currency): array
    {
        if (! app()->bound(TaxCalculator::class)) {
            return [[], self::BASE];
        }

        $country = config('storefront.tax_markets.'.strtoupper($currency));

        if (! is_string($country) || $country === '') {
            return [[], self::BASE];
        }

        $totals = app(TaxCalculator::class)->calculate([new InvoiceLineData('Price', self::BASE, billableType: BillableType::Service)], $currency, $country, null);
        $taxes = array_values(array_filter($totals->taxes, fn (array $tax): bool => $tax['rate'] > 0));

        return [array_map(fn (array $tax): array => ['name' => $tax['name'], 'rate' => (float) $tax['rate']], $taxes), $totals->total];
    }

    private function percent(float $rate): string
    {
        return rtrim(rtrim(number_format($rate, 3, '.', ''), '0'), '.').'%';
    }
}
