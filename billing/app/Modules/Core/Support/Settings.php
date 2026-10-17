<?php

namespace App\Modules\Core\Support;

use App\Modules\Core\Models\Setting;
use Illuminate\Support\Facades\Cache;

/**
 * Runtime-editable settings. Lookup order: database value, then config('<key>'), then $default.
 * Keys are namespaced by module, e.g. "billing.invoice_prefix" (defaults in Config/billing.php).
 */
class Settings
{
    private const CACHE_KEY = 'core.settings';

    public function get(string $key, mixed $default = null): mixed
    {
        $values = $this->all();

        return array_key_exists($key, $values) ? $values[$key] : config($key, $default);
    }

    public function set(string $key, mixed $value): void
    {
        Setting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function setMany(array $values): void
    {
        foreach ($values as $key => $value) {
            Setting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        }

        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, fn (): array => Setting::query()->pluck('value', 'key')->all());
    }
}
