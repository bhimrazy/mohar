<?php

namespace App\Modules\Services\Support;

use App\Data\Integrations\SettingField;
use App\Enums\FieldType;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Services\Models\Server;
use App\Support\Services\ProvisioningDriverRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * A server's driver-declared settings (DriverMetadata::$serverFields): validation, merging a submitted form into what
 * is stored, and what the form may show. Secret values follow the gateway rules: never sent back to the browser, an
 * empty input keeps the stored value, and a change is audited as "rotated".
 */
class ServerSettings
{
    /**
     * The server setting a driver declares when it can pin the server's certificate: an SPKI SHA-256, base64
     * (optionally prefixed `sha256//`). Its format is checked on save.
     */
    public const CERTIFICATE_PIN = 'spki_pin';

    public function __construct(private ProvisioningDriverRegistry $drivers, private ActivityLogger $activity) {}

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
            // A secret counts as stored only when it was stored for this driver: switching drivers drops the old values.
            $stored = $field->isSecret() && $server?->driver === $driverKey && (string) ($server->settings[$field->key] ?? '') !== '';
            $rules["settings.{$field->key}"] = $this->fieldRules($field, $stored);
        }

        return $rules;
    }

    /**
     * Validation rules of one setting by its type.
     *
     * @return list<mixed>
     */
    private function fieldRules(SettingField $field, bool $stored): array
    {
        return [
            $field->required && ! $stored && $field->type !== FieldType::Boolean ? 'required' : 'nullable',
            ...match ($field->type) {
                FieldType::Number => ['numeric'],
                FieldType::Boolean => [Rule::in(['0', '1', 'true', 'false', 0, 1, true, false])],
                FieldType::Url => ['url', 'max:2000'],
                FieldType::Select => $field->optionsSource === null ? [Rule::in(array_map('strval', array_keys($field->options)))] : ['string', 'max:255'],
                FieldType::Text, FieldType::SecretText => ['string', 'max:20000'],
                default => ['string', 'max:2000'],
            },
            ...($field->key === self::CERTIFICATE_PIN ? ['regex:#^(sha256//)?[A-Za-z0-9+/]{43}=$#'] : []),
        ];
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
        $submitted = $this->withDefaults($driverKey, $submitted);
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
     * Submitted settings with each absent non-secret field set to its default, so a default the form shows is the
     * value that is validated and stored.
     *
     * @param  array<string, mixed>  $submitted
     * @return array<string, mixed>
     */
    public function withDefaults(string $driverKey, array $submitted): array
    {
        foreach ($this->fields($driverKey) as $field) {
            if (! $field->isSecret() && $field->default !== null && ! array_key_exists($field->key, $submitted)) {
                $submitted[$field->key] = is_bool($field->default) ? ($field->default ? '1' : '0') : (string) $field->default;
            }
        }

        return $submitted;
    }

    /**
     * Store one setting outside the server form (e.g. a certificate pin staff trusted after a connection test),
     * audited like a form save: secrets as "rotated".
     */
    public function put(Server $server, string $key, mixed $value, ?string $reason = null): void
    {
        $field = collect($this->fields($server->driver))->first(fn (SettingField $field): bool => $field->key === $key)
            ?? throw new InvalidArgumentException("The {$server->driver} driver declares no server setting [{$key}].");
        $validator = Validator::make(['value' => $value], ['value' => $this->fieldRules($field, stored: false)]);

        if ($validator->fails()) {
            throw new InvalidArgumentException("Invalid value for the {$server->driver} server setting [{$key}]: ".$validator->errors()->first('value'));
        }

        DB::transaction(function () use ($server, $field, $key, $value, $reason): void {
            $locked = Server::query()->lockForUpdate()->findOrFail($server->id);
            $settings = $locked->settings ?? [];
            $before = $settings[$key] ?? null;
            $value = $this->cast($field, $value);

            if ($value === $before) {
                return;
            }

            if ($value === null || $value === '') {
                unset($settings[$key]);
            } else {
                $settings[$key] = $value;
            }

            $locked->update(['settings' => $settings ?: null]);
            $server->setRawAttributes($locked->getAttributes(), true);
            $this->activity->log("Server {$locked->name} settings changed", $locked, properties: array_filter([
                'changes' => ["settings.{$key}" => $field->isSecret() ? 'rotated' : ['from' => $before, 'to' => $value]],
                'reason' => $reason,
            ]));
        });
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
