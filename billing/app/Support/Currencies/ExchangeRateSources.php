<?php

namespace App\Support\Currencies;

use App\Contracts\Currencies\ExchangeRateSource;
use InvalidArgumentException;

/**
 * Registry of exchange-rate sources. Modules register source classes in bootModule():
 *
 *   app(ExchangeRateSources::class)->register(EcbRateSource::class);
 *
 * "manual" is reserved for staff-entered rates and cannot be registered.
 *
 * @api
 */
class ExchangeRateSources
{
    public const MANUAL = 'manual';

    /** @var array<string, ExchangeRateSource> */
    private array $resolved = [];

    /**
     * @param  class-string<ExchangeRateSource>  $class
     */
    public function register(string $class): void
    {
        $source = app($class);

        if ($source->key() === self::MANUAL || strlen($source->key()) > 30) {
            throw new InvalidArgumentException("Invalid exchange-rate source key [{$source->key()}].");
        }

        $this->resolved[$source->key()] = $source;
    }

    public function get(string $key): ?ExchangeRateSource
    {
        return $this->resolved[$key] ?? null;
    }

    /**
     * @return array<string, ExchangeRateSource>
     */
    public function all(): array
    {
        return $this->resolved;
    }

    /**
     * Sources that can quote a currency (and, when given, the default currency it is derived through).
     *
     * @return array<string, ExchangeRateSource>
     */
    public function for(string $code, ?string $default = null): array
    {
        return array_filter($this->resolved, fn (ExchangeRateSource $source): bool => in_array(strtoupper($code), $source->currencies(), true)
            && ($default === null || in_array(strtoupper($default), $source->currencies(), true)));
    }
}
