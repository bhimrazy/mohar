<?php

namespace App\Modules\Services\Support;

use App\Data\Integrations\SettingField;
use App\Enums\FieldType;
use App\Modules\Services\Models\Server;
use App\Support\Services\ProvisioningDriverRegistry;
use Illuminate\Validation\Rule;

/**
 * A server's driver-declared settings (DriverMetadata::$serverFields): validation, merging a submitted form into what
 * is stored, and what the form may show. Secret values follow the gateway rules: never sent back to the browser, an
 * empty input keeps the stored value, and a change is audited as "rotated".
 */
class ServerSettings
{
    public function __construct(private ProvisioningDriverRegistry $drivers) {}

    /**
     * @return list<SettingField>
     */
    public function fields(string $driverKey): array
    {
        return $this->drivers->has($driverKey) ? $this->drivers->metadata($driverKey)->serverFields : [];
    }

    /**
     * @return list<string>
     */
    public function secretKeys(string $driverKey): array
    {
        return array_values(array_map(fn (SettingField $field): string => $field->key, array_filter($this->fields($driverKey), fn (SettingField $field): bool => $field->isSecret())));
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(string $driverKey, ?Server $server): array
    {
        $rules = ['settings' => ['array']];

        foreach ($this->fields($driverKey) as $field) {
            $stored = $field->isSecret() && (string) ($server?->settings[$field->key] ?? '') !== '';
            $rules["settings.{$field->key}"] = [
                $field->required && ! $stored && $field->type !== FieldType::Boolean ? 'required' : 'nullable',
                ...match ($field->type) {
                    FieldType::Number => ['numeric'],
                    FieldType::Boolean => [Rule::in(['0', '1', 'true', 'false', 0, 1, true, false])],
                    FieldType::Url => ['url', 'max:2000'],
                    FieldType::Select => $field->optionsSource === null ? [Rule::in(array_map('strval', array_keys($field->options)))] : ['string', 'max:255'],
                    FieldType::Text, FieldType::SecretText => ['string', 'max:20000'],
                    default => ['string', 'max:2000'],
                },
            ];
        }

        return $rules;
    }

    /**
     * The settings to store and the audit entries for the change.
     *
     * @param  array<string, mixed>  $submitted
     * @return array{settings: array<string, mixed>, changes: array<string, mixed>}
     */
    public function merge(string $driverKey, ?Server $server, array $submitted): array
    {
        $stored = $server?->driver === $driverKey ? ($server->settings ?? []) : [];
        $settings = [];
        $changes = [];

        foreach ($this->fields($driverKey) as $field) {
            $before = $stored[$field->key] ?? null;
            $value = $this->cast($field, $submitted[$field->key] ?? null);

            if ($field->isSecret() && ($value === null || $value === '')) {
                $value = $before;
            }

            if ($value !== null && $value !== '') {
                $settings[$field->key] = $value;
            }

            if ($value !== $before) {
                $changes["settings.{$field->key}"] = $field->isSecret() ? 'rotated' : ['from' => $before, 'to' => $value];
            }
        }

        return ['settings' => $settings, 'changes' => $changes];
    }

    /**
     * Form values: non-secret settings, and for each secret only whether a value is stored.
     *
     * @return array{values: array<string, string>, secrets_set: array<string, bool>}
     */
    public function forForm(Server $server): array
    {
        $values = [];
        $set = [];

        foreach ($this->fields($server->driver) as $field) {
            $value = $server->settings[$field->key] ?? null;

            if ($field->isSecret()) {
                $set[$field->key] = $value !== null && $value !== '';

                continue;
            }

            $values[$field->key] = match (true) {
                is_bool($value) => $value ? '1' : '0',
                $value === null => (string) ($field->default ?? ''),
                default => (string) $value,
            };
        }

        return ['values' => $values, 'secrets_set' => $set];
    }

    private function cast(SettingField $field, mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match ($field->type) {
            FieldType::Boolean => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            FieldType::Number => is_numeric($value) ? $value + 0 : null,
            default => is_scalar($value) ? (string) $value : null,
        };
    }
}
