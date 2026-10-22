<?php

namespace App\Support\Security;

use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use LogicException;

/**
 * Every place a module keeps data encrypted with APP_KEY that outlives a key rotation (ADR 0012 registry shape,
 * ADR 0022), so `security:reencrypt` can move it to a new key. Modules register in bootModule(), keys
 * `<module>` or `<module>_<part>`:
 *
 *   app(EncryptedStorage::class)->register('billing_gateways', new EncryptedModel(Gateway::class));
 *   app(EncryptedStorage::class)->register('core_settings', new EncryptedColumn('settings', 'value', ...));
 *   app(EncryptedStorage::class)->register('kyc_documents_files', new EncryptedFiles('kyc_documents', [...], 'objects'));
 *
 * tests/Feature/Security/EncryptedStorageCoverageTest fails when an encrypted cast or a direct use of the encrypter
 * is not covered here.
 *
 * @api
 */
final class EncryptedStorage
{
    private const KEY_PATTERN = '/^[a-z0-9_]{1,50}$/';

    /** @var array<string, EncryptedModel|EncryptedColumn|EncryptedFiles> */
    private array $items = [];

    public function register(string $key, EncryptedModel|EncryptedColumn|EncryptedFiles $target, bool $replace = false): void
    {
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new InvalidArgumentException("Invalid encrypted-storage key [{$key}].");
        }

        if (isset($this->items[$key])) {
            if (! $replace) {
                throw new LogicException("Encrypted storage is already registered as [{$key}].");
            }

            Log::info('Encrypted storage registration replaced.', ['key' => $key]);
        }

        foreach ($this->items as $other => $item) {
            if ($other !== $key && self::identity($item) === self::identity($target)) {
                throw new LogicException("Encrypted storage [{$key}] is the same as [{$other}].");
            }
        }

        $this->items[$key] = $target;
    }

    public function has(string $key): bool
    {
        return isset($this->items[$key]);
    }

    /**
     * @return list<string> in registration order
     */
    public function keys(): array
    {
        return array_keys($this->items);
    }

    /**
     * @return array<string, EncryptedModel|EncryptedColumn|EncryptedFiles>
     */
    public function all(): array
    {
        return $this->items;
    }

    private static function identity(EncryptedModel|EncryptedColumn|EncryptedFiles $target): string
    {
        return $target instanceof EncryptedModel ? 'model:'.ltrim($target->model, '\\') : $target->name();
    }
}
