<?php

namespace App\Modules\Platform\Drivers\ExchangeRates;

use App\Contracts\Currencies\ExchangeRateSource;
use App\Data\Currencies\RateSnapshot;
use App\Modules\Core\Support\Settings;
use App\Support\Currencies\Decimal;
use App\Support\Currencies\ExchangeRateUnavailable;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Nepal Rastra Bank official foreign exchange rates (FOREX API v1, https://www.nrb.org.np/api-docs-v1):
 * GET /api/forex/v1/rates?page&per_page&from&to returns daily sets of buy/sell rates in NPR per `unit`
 * of each currency (e.g. 100 INR). The latest published day is used; which rate is taken is the
 * platform.nrb_rate_type setting: mid ((buy + sell) / 2, default), buy or sell.
 */
class NrbRateSource implements ExchangeRateSource
{
    /** Rate types NRB publishes, for the setting. */
    public const RATE_TYPES = ['mid' => 'Mid rate (average of buy and sell)', 'buy' => 'Buying rate', 'sell' => 'Selling rate'];

    public function __construct(private Settings $settings) {}

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
        $used = match ($this->rateType()) {
            'buy' => 'the buying rate',
            'sell' => 'the selling rate',
            default => 'the mid rate, halfway between buying and selling',
        };

        return "Nepal's central bank publishes NPR buying and selling rates every day; {$used} is used (Settings → Exchange rates). Covers NPR, INR, USD and about 20 more.";
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
                ->withoutRedirecting()
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

        if (strlen($response->body()) > (int) config('platform.exchange_rates.max_bytes', 1_048_576)) {
            throw new ExchangeRateUnavailable('The Nepal Rastra Bank API returned an unexpectedly large response.');
        }

        $json = rescue(fn (): mixed => $response->json(), null, report: false);

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
        $type = $this->rateType();

        foreach ($latest['rates'] as $row) {
            $code = strtoupper((string) data_get($row, 'currency.iso3', data_get($row, 'currency.ISO3', '')));
            $unit = (string) data_get($row, 'currency.unit', '');
            $buy = trim((string) data_get($row, 'buy', ''));
            $sell = trim((string) data_get($row, 'sell', ''));

            if (preg_match('/^[A-Z]{3}$/', $code) !== 1 || $code === 'NPR') {
                continue;
            }

            // Both rates must be plausible positive numbers with buy ≤ sell; anything else drops the currency.
            if (! Decimal::isPlausible($unit) || ! Decimal::isPlausible($buy) || ! Decimal::isPlausible($sell) || Decimal::compare($buy, $sell) > 0) {
                continue;
            }

            $rate = match ($type) {
                'buy' => $buy,
                'sell' => $sell,
                default => Decimal::divide(Decimal::add($buy, $sell), '2', 12),
            };

            if (Decimal::isPositive($rate)) {
                $rates[$code] = ['units' => $unit, 'base' => $rate];
            }
        }

        if ($rates === []) {
            throw new ExchangeRateUnavailable('The Nepal Rastra Bank API response contained no usable rates.');
        }

        // Rates apply to the stated Nepal business day (published shortly after midnight there); noon keeps
        // the calendar date the same in every staff timezone.
        return new RateSnapshot($this->key(), 'NPR', $rates, CarbonImmutable::parse($latest['date'].' 12:00', 'Asia/Kathmandu')->utc());
    }

    /**
     * @return 'mid'|'buy'|'sell'
     */
    public function rateType(): string
    {
        $type = (string) $this->settings->get('platform.nrb_rate_type', 'mid');

        return match ($type) {
            'buy' => 'buy',
            'sell' => 'sell',
            default => 'mid',
        };
    }
}
