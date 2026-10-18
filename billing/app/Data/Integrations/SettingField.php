<?php

namespace App\Data\Integrations;

use App\Enums\FieldType;
use InvalidArgumentException;

/**
 * One staff-configurable setting of an integration (gateway, provisioning driver, registrar). The settings form
 * renders it, validation reads `required`, and storage encrypts it when the type is secret.
 *
 * A secret field never has a default: a default would ship a credential in code.
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
    ) {
        if ($type->isSecret() && $default !== null) {
            throw new InvalidArgumentException("The secret setting [{$key}] cannot have a default.");
        }

        if ($optionsSource !== null && $type !== FieldType::Select) {
            throw new InvalidArgumentException("Only a select setting can take remote options; [{$key}] is {$type->value}.");
        }
    }

    public const OPTIONS_REMOTE = 'remote';

    public function isSecret(): bool
    {
        return $this->type->isSecret();
    }

    /**
     * Read an array-shaped field: the gateway shape (`type: secret|secret_text|…`, `options` as value => label)
     * or the older provisioning shape (`type: password`, `secret: bool`, `options` as a list of values). Throws for
     * a secret with a default or remote options on a non-select, like the constructor.
     *
     * @param  array{key: string, label: string, type?: string, secret?: bool, required?: bool, help?: string|null, options?: array<array-key, string>, default?: string|int|bool|null, options_source?: string|null}  $field
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
        );
    }

    /**
     * @return array{key: string, label: string, type: string, secret: bool, required: bool, help: string|null, options: array<array-key, string>, default: string|int|bool|null, options_source: string|null}
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
        ];
    }
}
