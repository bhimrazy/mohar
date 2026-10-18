<?php

namespace App\Modules\Platform\Support;

use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/**
 * One cache lock for every exchange-rate change (scheduled and "Update rates now" fetches, manual rate edits,
 * review decisions, default-currency rebases), so they never interleave across requests, workers or servers.
 */
class ExchangeRateLock
{
    public const KEY = 'platform.exchange-rates';

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     *
     * @throws ValidationException when another change holds the lock for longer than the wait
     */
    public static function run(Closure $callback, ?int $waitSeconds = null): mixed
    {
        try {
            return Cache::lock(self::KEY, 300)->block($waitSeconds ?? (int) config('platform.exchange_rates.lock_wait_seconds', 15), $callback);
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages(['rates' => __('Another exchange-rate change is in progress. Try again in a minute.')]);
        }
    }
}
