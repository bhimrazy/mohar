<?php

namespace App\Data\Integrations;

use App\Enums\FieldType;

/**
 * One staff-configurable setting of an integration (gateway, provisioning driver, registrar). The settings form
 * renders it, validation reads `required`, and storage encrypts it when the type is secret.
 *
 * A secret field never has a default: a default would ship a credential in code.
 *
 * A Select field may take its options from the remote system instead of $options: $optionsSource names where, for
 * example `remote:packages` (the packages a control panel offers, through the ListsPackages capability). The host
 * fills the select and falls back to free text when the remote system cannot be reached.
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
    ) {}

    public function isSecret(): bool
    {
        return $this->type->isSecret();
    }

    /**
     * Read an array-shaped field: the gateway shape (`type: secret|secret_text|…`, `options` as value => label)
     * or the older provisioning shape (`type: password`, `secret: bool`, `options` as a list of values).
     *
     * @param  array{key: string, label: string, type?: string, secret?: bool, required?: bool, help?: string|null, options?: array<array-key, string>, default?: string|int|bool|null, options_source?: string|null}  $field
     */
    public static function fromArray(array $field): self
    {
        $options = $field['options'] ?? [];

        if (array_is_list($options)) {
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
