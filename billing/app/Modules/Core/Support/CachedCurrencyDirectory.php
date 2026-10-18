<?php

namespace App\Modules\Core\Support;

use App\Contracts\Currencies\CurrencyDirectory;
use App\Data\Currencies\CurrencyDisplay;
use App\Modules\Core\Models\Currency;
use Illuminate\Support\Facades\Cache;

/**
 * Currency display settings from the currencies table, cached across requests and workers. The Currency
 * model flushes the cache on save and delete; within one request the list is also memoised.
 */
class CachedCurrencyDirectory implements CurrencyDirectory
{
    private const CACHE_KEY = 'core.currency_displays.v1';

    /** Bumped on every flush so memoised copies in live instances are discarded too. */
    private static int $generation = 0;

    /** @var array<string, CurrencyDisplay>|null */
    private ?array $displays = null;

    private int $loadedGeneration = -1;

    public static function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
        self::$generation++;
    }

    public function display(string $code): ?CurrencyDisplay
    {
        return $this->load()[strtoupper($code)] ?? null;
    }

    public function displays(): array
    {
        return array_values($this->load());
    }

    /**
     * @return array<string, CurrencyDisplay>
     */
    private function load(): array
    {
        if ($this->displays !== null && $this->loadedGeneration === self::$generation) {
            return $this->displays;
        }

        $this->loadedGeneration = self::$generation;

        /** @var list<array{code: string, prefix: string, suffix: string, decimals: int, format: string}> $rows */
        $rows = Cache::rememberForever(self::CACHE_KEY, fn (): array => Currency::query()
            ->orderByDesc('is_default')->orderBy('code')->orderBy('id')
            ->get(['code', 'prefix', 'suffix', 'decimals', 'format'])
            ->map(fn (Currency $currency): array => $currency->display()->toArray())
            ->all());

        $displays = [];

        foreach ($rows as $row) {
            $displays[$row['code']] = new CurrencyDisplay($row['code'], $row['prefix'], $row['suffix'], $row['decimals'], $row['format']);
        }

        return $this->displays = $displays;
    }
}
