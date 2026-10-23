<?php

namespace App\Modules\Domains\Services;

use App\Contracts\Domains\Registrar\DeclaresTldAttributes;
use App\Contracts\Domains\TldAttributes;
use App\Data\Integrations\SettingField;
use App\Enums\FieldType;
use App\Modules\Domains\Models\Tld;
use App\Support\Domains\RegistrarDriverRegistry;
use Illuminate\Validation\ValidationException;

/**
 * Extension details the registrar asks for (ADR 0009 Amendment 2). The field list comes from the extension's
 * registrar driver (`DeclaresTldAttributes`); this class keeps no list of its own. The same list renders the form and
 * validates what comes back, so a value the form could not have offered is refused.
 *
 * Values may be personal data: they are never logged, and error messages name the field, not the value.
 */
class TldAttributeService implements TldAttributes
{
    private const MAX_LENGTH = 255;

    public function __construct(private RegistrarDriverRegistry $drivers, private RegistrarManager $accounts) {}

    /**
     * @return list<SettingField>
     */
    public function fieldsFor(Tld $tld): array
    {
        $key = $tld->registrar_key;

        if ($key === null || $tld->registered_by_client || ! $this->drivers->supports($key, RegistrarDriverRegistry::TLD_ATTRIBUTES) || ! $this->accounts->isActive($key)) {
            return [];
        }

        $driver = $this->drivers->get($key);

        if (! $driver instanceof DeclaresTldAttributes) {
            return [];
        }

        return array_values(array_filter(
            $driver->tldAttributeFields($tld->extension),
            fn (SettingField $field): bool => ! $field->isSecret(),
        ));
    }

    public function fieldsByTld(): array
    {
        $keys = array_values(array_filter($this->drivers->keys(), fn (string $key): bool => $this->drivers->supports($key, RegistrarDriverRegistry::TLD_ATTRIBUTES)));

        if ($keys === []) {
            return [];
        }

        $fields = [];

        Tld::query()->active()->whereIn('registrar_key', $keys)->where('allow_register', true)->orderBy('id')->limit(1000)->get()
            ->each(function (Tld $tld) use (&$fields): void {
                if (($list = $this->fieldsFor($tld)) !== []) {
                    $fields[$tld->extension] = $list;
                }
            });

        return $fields;
    }

    public function clean(string $tld, mixed $input): array
    {
        $model = Tld::query()->where('extension', Tld::normaliseExtension($tld))->first();
        $fields = $model === null ? [] : $this->fieldsFor($model);
        $input = is_array($input) ? $input : [];
        $clean = [];
        $errors = [];

        foreach ($fields as $field) {
            $raw = $input[$field->key] ?? null;
            $value = is_scalar($raw) ? trim((string) $raw) : '';
            $name = 'tld_attributes.'.$field->key;

            if ($field->type === FieldType::Boolean) {
                $on = in_array(strtolower($value), ['1', 'true', 'on', 'yes'], true);

                if ($field->required && ! $on) {
                    $errors[$name] = __('Confirm: :field.', ['field' => $field->label]);
                } else {
                    $clean[$field->key] = $on ? 'true' : 'false';
                }

                continue;
            }

            if ($value === '') {
                if ($field->required) {
                    $errors[$name] = __('Enter :field.', ['field' => mb_strtolower($field->label)]);
                }

                continue;
            }

            $error = match (true) {
                mb_strlen($value) > self::MAX_LENGTH, preg_match('/[\x00-\x1F\x7F]/', $value) === 1 => __('Check :field.', ['field' => mb_strtolower($field->label)]),
                $field->type === FieldType::Select && ! array_key_exists($value, $field->options) => __('Choose one of the listed options for :field.', ['field' => mb_strtolower($field->label)]),
                $field->type === FieldType::Number && ! is_numeric($value) => __('Enter a number for :field.', ['field' => mb_strtolower($field->label)]),
                $field->type === FieldType::Url && filter_var($value, FILTER_VALIDATE_URL) === false => __('Enter a full web address for :field.', ['field' => mb_strtolower($field->label)]),
                default => null,
            };

            if ($error !== null) {
                $errors[$name] = $error;
            } else {
                $clean[$field->key] = $value;
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $clean;
    }
}
