<?php

namespace App\Modules\Platform\Drivers\ExchangeRates;

use App\Contracts\Currencies\ExchangeRateSource;
use App\Data\Currencies\RateSnapshot;
use App\Support\Currencies\Decimal;
use App\Support\Currencies\ExchangeRateUnavailable;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Nepal Rastra Bank official foreign exchange rates (FOREX API v1, https://www.nrb.org.np/api-docs-v1):
 * GET /api/forex/v1/rates?page&per_page&from&to returns daily sets of buy/sell rates in NPR per `unit`
 * of each currency (e.g. 100 INR). We use the mid rate, (buy + sell) / 2, and the latest published day.
 */
class NrbRateSource implements ExchangeRateSource
{
    private const CURRENCIES = [
        'NPR', 'INR', 'USD', 'EUR', 'GBP', 'CHF', 'AUD', 'CAD', 'SGD', 'JPY', 'CNY', 'SAR', 'QAR', 'THB', 'AED',
        'MYR', 'KRW', 'SEK', 'DKK', 'HKD', 'KWD', 'BHD', 'OMR',
    ];

    public function key(): string
    {
        return 'nrb';
    }

    public function label(): string
    {
        return 'Nepal Rastra Bank official rates';
    }

    public function description(): string
    {
        return 'Nepal\'s central bank publishes NPR buy and sell rates every day; the mid rate is used. Covers NPR, INR, USD and about 20 more.';
    }

    public function currencies(): array
    {
        return self::CURRENCIES;
    }

    public function fetch(): RateSnapshot
    {
        $today = CarbonImmutable::now('Asia/Kathmandu');

        try {
            $response = Http::timeout((int) config('platform.exchange_rates.timeout', 10))
                ->connectTimeout((int) config('platform.exchange_rates.connect_timeout', 5))
                ->acceptJson()
                ->get((string) config('platform.exchange_rates.nrb_url'), [
                    'page' => 1,
                    'per_page' => 10,
                    'from' => $today->subDays(7)->toDateString(),
                    'to' => $today->toDateString(),
                ]);
        } catch (ConnectionException) {
            throw new ExchangeRateUnavailable('The Nepal Rastra Bank API could not be reached.');
        }

        if (! $response->successful()) {
            throw new ExchangeRateUnavailable("The Nepal Rastra Bank API answered with HTTP {$response->status()}.");
        }

        $json = $response->json();

        return $this->parse(is_array($json) ? $json : []);
    }

    /**
     * @param  array<mixed>  $json
     */
    public function parse(array $json): RateSnapshot
    {
        $days = data_get($json, 'data.payload');

        if (! is_array($days) || $days === []) {
            throw new ExchangeRateUnavailable('The Nepal Rastra Bank API returned no published rates.');
        }

        $latest = collect($days)
            ->filter(fn (mixed $day): bool => is_array($day) && is_string($day['date'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $day['date']) === 1 && is_array($day['rates'] ?? null))
            ->sortByDesc('date')
            ->first();

        if (! is_array($latest)) {
            throw new ExchangeRateUnavailable('The Nepal Rastra Bank API response could not be read.');
        }

        $rates = [];

        foreach ($latest['rates'] as $row) {
            $code = strtoupper((string) data_get($row, 'currency.iso3', data_get($row, 'currency.ISO3', '')));
            $unit = (string) data_get($row, 'currency.unit', '');
            $buy = trim((string) data_get($row, 'buy', ''));
            $sell = trim((string) data_get($row, 'sell', ''));

            if (preg_match('/^[A-Z]{3}$/', $code) !== 1 || $code === 'NPR') {
                continue;
            }

            if (! Decimal::isValid($unit) || ! Decimal::isPositive($unit) || ! Decimal::isValid($buy) || ! Decimal::isValid($sell)) {
                continue;
            }

            $mid = Decimal::divide(Decimal::add($buy, $sell), '2', 6);

            if (Decimal::isPositive($mid)) {
                $rates[$code] = ['units' => $unit, 'base' => $mid];
            }
        }

        if ($rates === []) {
            throw new ExchangeRateUnavailable('The Nepal Rastra Bank API response contained no usable rates.');
        }

        // NRB publishes the day's rates shortly after midnight Nepal time.
        return new RateSnapshot($this->key(), 'NPR', $rates, CarbonImmutable::parse($latest['date'], 'Asia/Kathmandu')->utc());
    }
}
