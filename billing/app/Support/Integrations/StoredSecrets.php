<?php

namespace App\Support\Integrations;

use App\Data\Integrations\SettingField;
use Closure;
use Illuminate\Contracts\Encryption\DecryptException;
use InvalidArgumentException;
use LogicException;

/**
 * Finds stored secret settings shorter than their field's minimum (ADR 0023 §2), so staff can replace them: such a
 * value was saved before the minimum existed, and the Redactor does not match a very short value. Each module that
 * stores integration secrets registers a source in bootModule():
 *
 *   app(StoredSecrets::class)->register('gateways', fn (): iterable => ...);
 *
 * A source yields one entry per stored record: a label for staff, its fields, and a closure that decrypts its values.
 * The closure is called here, so a record that cannot be decrypted (a missing key, ADR 0022) is reported as unreadable
 * and the check goes on. Values never leave this class; a finding names the setting by key and label only.
 *
 * @api
 */
class StoredSecrets
{
    private const KEY_PATTERN = '/^[a-z0-9_]{1,50}$/';

    /** @var array<string, Closure(): iterable<array{owner: string, fields: list<SettingField>, values: Closure(): array<array-key, mixed>}>> */
    private array $sources = [];

    /**
     * @param  Closure(): iterable<array{owner: string, fields: list<SettingField>, values: Closure(): array<array-key, mixed>}>  $source
     */
    public function register(string $key, Closure $source): void
    {
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new InvalidArgumentException("Invalid stored secrets key [{$key}]: use 1 to 50 lowercase letters, digits or underscores.");
        }

        if (isset($this->sources[$key])) {
            throw new LogicException("A stored secrets source is already registered as [{$key}].");
        }

        $this->sources[$key] = $source;
    }

    /**
     * Keys of the secret settings in $values that are shorter than their field allows.
     *
     * @param  list<SettingField>  $fields
     * @param  array<array-key, mixed>  $values
     * @return list<string>
     */
    public static function tooShort(array $fields, array $values): array
    {
        return array_values(array_map(
            fn (SettingField $field): string => $field->key,
            array_filter($fields, fn (SettingField $field): bool => $field->isTooShort($values[$field->key] ?? null)),
        ));
    }

    /**
     * Every stored secret setting below its minimum, and every record that could not be decrypted, across the
     * registered sources.
     *
     * @return array{short: list<array{source: string, owner: string, key: string, label: string, minimum: int}>, unreadable: list<array{source: string, owner: string}>}
     */
    public function check(): array
    {
        $short = [];
        $unreadable = [];

        foreach ($this->sources as $source => $entries) {
            foreach ($entries() as $entry) {
                try {
                    $values = ($entry['values'])();
                } catch (DecryptException) {
                    $unreadable[] = ['source' => $source, 'owner' => $entry['owner']];

                    continue;
                }

                foreach ($entry['fields'] as $field) {
                    if ($field->isTooShort($values[$field->key] ?? null)) {
                        $short[] = ['source' => $source, 'owner' => $entry['owner'], 'key' => $field->key, 'label' => $field->label, 'minimum' => $field->minimumSecretLength()];
                    }
                }
            }
        }

        return ['short' => $short, 'unreadable' => $unreadable];
    }
}
