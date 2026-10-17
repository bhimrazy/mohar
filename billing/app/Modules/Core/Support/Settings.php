<?php

namespace App\Modules\Core\Support;

use App\Modules\Core\Models\Setting;
use App\Support\Settings\SettingsRegistry;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

/**
 * Runtime-editable settings. Lookup order: database value, then config('<key>'), then $default.
 * Keys are namespaced by module, e.g. "billing.invoice_prefix" (defaults in Config/billing.php).
 *
 * Settings registered as secret (SettingDefinition::$secret) are stored encrypted at rest
 * ("enc:v1:" + Crypt::encryptString({"key", "value"}), so ciphertext is bound to its key). The cache only ever holds the ciphertext; values are
 * decrypted in get(), so plaintext secrets never reach the database, the cache store or all().
 */
class Settings
{
    private const CACHE_KEY = 'core.settings';

    public const ENCRYPTED_PREFIX = 'enc:v1:';

    public function __construct(private SettingsRegistry $registry) {}

    public function get(string $key, mixed $default = null): mixed
    {
        $values = $this->all();

        if (! array_key_exists($key, $values)) {
            return config($key, $default);
        }

        // Only registered secrets are ever decrypted, and only with a payload bound to this key.
        return $this->registry->isSecret($key) && self::isEncrypted($values[$key])
            ? $this->decrypt($key, $values[$key], $default)
            : $values[$key];
    }

    public function set(string $key, mixed $value): void
    {
        $this->setMany([$key => $value]);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function setMany(array $values): void
    {
        foreach ($values as $key => $value) {
            Setting::query()->updateOrCreate(['key' => $key], ['value' => $this->forStorage($key, $value)]);
        }

        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Raw stored values (secret settings stay encrypted here; use get() to read them).
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, fn (): array => Setting::query()->pluck('value', 'key')->all());
    }

    /**
     * Encrypt any secret setting still stored in plaintext (e.g. saved before encryption existed).
     * Returns the number of rows encrypted.
     */
    public function encryptStoredSecrets(): int
    {
        $count = 0;

        Setting::query()->orderBy('key')->get()->each(function (Setting $setting) use (&$count): void {
            if ($this->registry->isSecret($setting->key) && ! self::isEncrypted($setting->value)) {
                $setting->update(['value' => $this->forStorage($setting->key, $setting->value)]);
                $count++;
            }
        });

        Cache::forget(self::CACHE_KEY);

        return $count;
    }

    public static function isEncrypted(mixed $value): bool
    {
        return is_string($value) && str_starts_with($value, self::ENCRYPTED_PREFIX);
    }

    private function forStorage(string $key, mixed $value): mixed
    {
        if ($value === null || self::isEncrypted($value) || ! $this->registry->isSecret($key)) {
            return $value;
        }

        return self::ENCRYPTED_PREFIX.Crypt::encryptString((string) json_encode(['key' => $key, 'value' => $value]));
    }

    private function decrypt(string $key, string $value, mixed $default): mixed
    {
        try {
            $payload = json_decode(Crypt::decryptString(substr($value, strlen(self::ENCRYPTED_PREFIX))), true);
        } catch (DecryptException $exception) {
            // Unreadable with the current APP_KEY (e.g. rotated without APP_PREVIOUS_KEYS): treat as unset.
            report($exception);

            return $default;
        }

        // Ciphertext copied from another setting decrypts but belongs to a different key: refuse it.
        if (! is_array($payload) || ($payload['key'] ?? null) !== $key) {
            report(new DecryptException("Encrypted setting [{$key}] does not belong to this key."));

            return $default;
        }

        return $payload['value'] ?? $default;
    }
}
