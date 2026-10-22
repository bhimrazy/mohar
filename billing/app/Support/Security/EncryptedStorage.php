<?php

namespace App\Support\Security;

use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use LogicException;

/**
 * Encrypted values that no Eloquent encrypted cast declares (ADR 0012 registry shape), so `security:reencrypt` can
 * move them to the current APP_KEY. Modules register in bootModule():
 *
 *   app(EncryptedStorage::class)->register('settings.value', new EncryptedColumn('settings', 'value', ...));
 *   app(EncryptedStorage::class)->register('kyc_documents.files', new EncryptedFiles('kyc_documents', [...], 'objects'));
 *
 * Columns with an encrypted cast are discovered from the models and need no entry here.
 */
class EncryptedStorage
{
    private const KEY_PATTERN = '/^[a-z0-9_.]{1,100}$/';

    /** @var array<string, EncryptedColumn|EncryptedFiles> */
    private array $items = [];

    public function register(string $key, EncryptedColumn|EncryptedFiles $target, bool $replace = false): void
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

        $this->items[$key] = $target;
    }

    /**
     * @return array<string, EncryptedColumn|EncryptedFiles>
     */
    public function all(): array
    {
        return $this->items;
    }
}
