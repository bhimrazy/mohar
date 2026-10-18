<?php

namespace App\Modules\Platform\Drivers\ExchangeRates;

use App\Contracts\Currencies\ExchangeRateSource;
use App\Data\Currencies\RateSnapshot;
use App\Support\Currencies\Decimal;
use App\Support\Currencies\ExchangeRateUnavailable;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use SimpleXMLElement;
use Throwable;

/**
 * European Central Bank euro foreign exchange reference rates: one XML file with the latest working day's
 * rates, published around 16:00 CET (https://www.ecb.europa.eu/stats/policy_and_exchange_rates/euro_reference_exchange_rates/html/index.en.html).
 * Rates are units of each currency per 1 EUR; NPR is not covered.
 */
class EcbRateSource implements ExchangeRateSource
{
    private const NAMESPACE = 'http://www.ecb.int/vocabulary/2002-08-01/eurofxref';

    private const CURRENCIES = [
        'EUR', 'USD', 'JPY', 'CZK', 'DKK', 'GBP', 'HUF', 'PLN', 'RON', 'SEK', 'CHF', 'ISK', 'NOK', 'TRY', 'AUD',
        'BRL', 'CAD', 'CNY', 'HKD', 'IDR', 'ILS', 'INR', 'KRW', 'MXN', 'MYR', 'NZD', 'PHP', 'SGD', 'THB', 'ZAR',
    ];

    public function key(): string
    {
        return 'ecb';
    }

    public function label(): string
    {
        return 'European Central Bank reference rates';
    }

    public function description(): string
    {
        return 'Daily euro reference rates for about 30 currencies, published on working days around 16:00 CET. Does not cover NPR.';
    }

    public function currencies(): array
    {
        return self::CURRENCIES;
    }

    public function fetch(): RateSnapshot
    {
        try {
            $response = Http::timeout((int) config('platform.exchange_rates.timeout', 10))
                ->connectTimeout((int) config('platform.exchange_rates.connect_timeout', 5))
                ->accept('application/xml')
                ->get((string) config('platform.exchange_rates.ecb_url'));
        } catch (ConnectionException) {
            throw new ExchangeRateUnavailable('The European Central Bank feed could not be reached.');
        }

        if (! $response->successful()) {
            throw new ExchangeRateUnavailable("The European Central Bank feed answered with HTTP {$response->status()}.");
        }

        return $this->parse($response->body());
    }

    public function parse(string $xml): RateSnapshot
    {
        try {
            $document = new SimpleXMLElement($xml, LIBXML_NONET);
        } catch (Throwable) {
            throw new ExchangeRateUnavailable('The European Central Bank feed returned malformed XML.');
        }

        $day = $document->children(self::NAMESPACE)->Cube->Cube ?? null;
        $time = $day !== null ? (string) $day['time'] : '';

        if ($day === null || preg_match('/^\d{4}-\d{2}-\d{2}$/', $time) !== 1) {
            throw new ExchangeRateUnavailable('The European Central Bank feed has no dated rates.');
        }

        $rates = [];

        foreach ($day->children(self::NAMESPACE)->Cube as $cube) {
            $code = strtoupper((string) $cube['currency']);
            $rate = trim((string) $cube['rate']);

            if (preg_match('/^[A-Z]{3}$/', $code) === 1 && Decimal::isValid($rate) && Decimal::isPositive($rate)) {
                $rates[$code] = ['units' => $rate, 'base' => '1'];
            }
        }

        if ($rates === []) {
            throw new ExchangeRateUnavailable('The European Central Bank feed contained no rates.');
        }

        // Reference rates are set at about 14:10 CET (published around 16:00) on the stated day.
        return new RateSnapshot($this->key(), 'EUR', $rates, CarbonImmutable::parse($time.' 14:10', 'Europe/Berlin')->utc());
    }
}
