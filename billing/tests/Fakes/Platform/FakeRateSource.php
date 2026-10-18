<?php

namespace Tests\Fakes\Platform;

use App\Contracts\Currencies\ExchangeRateSource;
use App\Data\Currencies\RateSnapshot;
use App\Support\Currencies\ExchangeRateUnavailable;
use Carbon\CarbonImmutable;

/**
 * Rate source for tests: quotes units per 1 USD as given, or fails like an outage.
 */
final class FakeRateSource implements ExchangeRateSource
{
    /** @var array<string, string> */
    public static array $rates = [];

    public static bool $down = false;

    public static int $fetches = 0;

    /**
     * @param  array<string, string>  $rates  ISO code => units per 1 USD
     */
    public static function quote(array $rates): void
    {
        self::$rates = $rates;
        self::$down = false;
    }

    public function key(): string
    {
        return 'fake';
    }

    public function label(): string
    {
        return 'Test feed';
    }

    public function description(): string
    {
        return 'Rates set by the test.';
    }

    public function currencies(): array
    {
        return ['USD', 'EUR', 'INR', 'NPR'];
    }

    public function fetch(): RateSnapshot
    {
        self::$fetches++;

        if (self::$down) {
            throw new ExchangeRateUnavailable('The test feed could not be reached.');
        }

        return new RateSnapshot('fake', 'USD', array_map(fn (string $rate): array => ['units' => $rate, 'base' => '1'], self::$rates), CarbonImmutable::parse('2026-10-18 06:00'));
    }
}
