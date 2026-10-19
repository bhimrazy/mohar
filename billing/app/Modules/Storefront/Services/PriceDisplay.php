<?php

namespace App\Modules\Storefront\Services;

use App\Contracts\Billing\TaxCalculator;
use App\Data\Billing\InvoiceLineData;
use App\Enums\BillableType;
use App\Modules\Core\Support\Settings;

/**
 * How prices read on public pages in one currency (spec §5.4): with or without tax, and the tax statement shown under
 * every price group and in the footer. Rates come from Billing's tax rules for the company's default country, so the
 * statement matches what checkout charges a visitor there. Showing prices with tax is a per-currency choice, off by
 * default; when Billing already prices tax-inclusive, amounts are shown as they are.
 */
class PriceDisplay
{
    private const BASE = 1_000_000;

    public function __construct(private Settings $settings) {}

    /**
     * @param  array<string, mixed>  $content  normalised site content
     * @return array{multiplier: int, note: string|null, inclusive: bool}
     */
    public function forCurrency(string $currency, array $content): array
    {
        [$taxes, $total] = $this->taxes($currency);
        $label = implode(' + ', array_map(fn (array $tax): string => $this->percent($tax['rate']).' '.$tax['name'], $taxes));
        $billingInclusive = $taxes !== [] && $total === self::BASE;
        $wantsInclusive = in_array($currency, $content['prices']['tax_inclusive'], true);
        $inclusive = $taxes !== [] && ($billingInclusive || $wantsInclusive);

        $note = $content['prices']['notes'][$currency] ?? null;
        $note ??= match (true) {
            $taxes === [] => null,
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

        $country = $this->settings->get('core.default_country');
        $totals = app(TaxCalculator::class)->calculate([new InvoiceLineData('Price', self::BASE, billableType: BillableType::Service)], $currency, is_string($country) ? $country : null, null);
        $taxes = array_values(array_filter($totals->taxes, fn (array $tax): bool => $tax['rate'] > 0));

        return [array_map(fn (array $tax): array => ['name' => $tax['name'], 'rate' => (float) $tax['rate']], $taxes), $totals->total];
    }

    private function percent(float $rate): string
    {
        return rtrim(rtrim(number_format($rate, 3, '.', ''), '0'), '.').'%';
    }
}
