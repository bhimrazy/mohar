<?php

namespace App\Data\Integrations;

use App\Enums\FieldType;
use InvalidArgumentException;

/**
 * One staff-configurable setting of an integration (gateway, provisioning driver, registrar). The settings form
 * renders it, validation reads `required`, and storage encrypts it when the type is secret.
 *
 * A secret field never has a default: a default would ship a credential in code. A new secret value shorter than
 * minimumSecretLength() bytes is refused at save (ADR 0023 §2): the Redactor does not match very short values, since
 * that would hide ordinary words. A field with a shorter known format (a 6-digit PIN) declares $minLength, at least
 * MIN_SECRET_FLOOR.
 *
 * A Select field may take its options from the remote system instead of $options: `$optionsSource = 'remote'` (the
 * only value so far) asks the driver for them (ProvidesFieldOptions for provisioning drivers). The host fills the
 * select and falls back to free text when the remote system cannot be reached.
 */
final readonly class SettingField
{
    /**
     * @param  array<array-key, string>  $options  value => label, for Select fields
     */
    public function __construct(
        public string $key,
        public string $label,
        public FieldType $type = FieldType::String,
        public bool $required = false,
        public ?string $help = null,
        public array $options = [],
        public string|int|bool|null $default = null,
        public ?string $optionsSource = null,
        public ?int $minLength = null,
    ) {
        if ($type->isSecret() && $default !== null) {
            throw new InvalidArgumentException("The secret setting [{$key}] cannot have a default.");
        }

        if ($optionsSource !== null && $type !== FieldType::Select) {
            throw new InvalidArgumentException("Only a select setting can take remote options; [{$key}] is {$type->value}.");
        }

        if ($minLength !== null && (! $type->isSecret() || $minLength < self::MIN_SECRET_FLOOR)) {
            throw new InvalidArgumentException('Only a secret setting declares a minimum length, of at least '.self::MIN_SECRET_FLOOR." bytes; [{$key}] declares {$minLength}.");
        }
    }

    public const OPTIONS_REMOTE = 'remote';

    /** Shortest new secret value, in bytes, unless the field declares $minLength. */
    public const MIN_SECRET_BYTES = 8;

    /** Lowest $minLength a field may declare: the Redactor's match minimum (ADR 0023 §2). */
    public const MIN_SECRET_FLOOR = 4;

    public function minimumSecretLength(): int
    {
        return $this->minLength ?? self::MIN_SECRET_BYTES;
    }

    /**
     * Whether $value is a secret too short for this field: non-empty and, once trimmed (as it is matched), under the
     * minimum. Always false for a non-secret field.
     */
    public function isTooShort(mixed $value): bool
    {
        if (! $this->isSecret() || ! is_scalar($value)) {
            return false;
        }

        $trimmed = trim((string) $value);

        return $trimmed !== '' && strlen($trimmed) < $this->minimumSecretLength();
    }

    public function isSecret(): bool
    {
        return $this->type->isSecret();
    }

    /**
     * Read an array-shaped field: the gateway shape (`type: secret|secret_text|…`, `options` as value => label)
     * or the older provisioning shape (`type: password`, `secret: bool`, `options` as a list of values). Throws for
     * a secret with a default or remote options on a non-select, like the constructor.
     *
     * @param  array{key: string, label: string, type?: string, secret?: bool, required?: bool, help?: string|null, options?: array<array-key, string>, default?: string|int|bool|null, options_source?: string|null, min_length?: int|null}  $field
     */
    public static function fromArray(array $field): self
    {
        $options = $field['options'] ?? [];

        // Only the older provisioning shape (recognised by its `secret` flag) lists bare values; a gateway map keyed
        // 0..n ([0 => 'Off', 1 => 'On']) is a value => label map like any other.
        if (array_key_exists('secret', $field) && array_is_list($options)) {
            $options = array_combine($options, $options);
        }

        return new self(
            key: $field['key'],
            label: $field['label'],
            type: FieldType::fromLegacy($field['type'] ?? FieldType::String->value, $field['secret'] ?? false),
            required: $field['required'] ?? false,
            help: $field['help'] ?? null,
            options: $options,
            default: $field['default'] ?? null,
            optionsSource: $field['options_source'] ?? null,
            minLength: $field['min_length'] ?? null,
        );
    }

    /**
     * @return array{key: string, label: string, type: string, secret: bool, required: bool, help: string|null, options: array<array-key, string>, default: string|int|bool|null, options_source: string|null, min_length: int|null}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'type' => $this->type->value,
            'secret' => $this->isSecret(),
            'required' => $this->required,
            'help' => $this->help,
            'options' => $this->options,
            'default' => $this->default,
            'options_source' => $this->optionsSource,
            'min_length' => $this->isSecret() ? $this->minimumSecretLength() : null,
        ];
    }
}
