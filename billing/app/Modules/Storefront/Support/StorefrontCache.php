<?php

namespace App\Modules\Storefront\Support;

use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Cache for public-site payloads (spec §10): every key carries a generation number, so flush() drops them all at
 * once on any store. Entries also expire after the configured TTL as a safety net for changes no event announces.
 */
class StorefrontCache
{
    private const GENERATION = 'storefront.cache.generation';

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function remember(string $key, Closure $callback): mixed
    {
        $generation = (int) Cache::get(self::GENERATION, 0);

        return Cache::remember("storefront.{$generation}.{$key}", (int) config('storefront.cache_seconds', 600), $callback);
    }

    public function flush(): void
    {
        Cache::forever(self::GENERATION, (int) Cache::get(self::GENERATION, 0) + 1);
    }
}
