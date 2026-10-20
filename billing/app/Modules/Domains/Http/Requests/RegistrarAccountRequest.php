<?php

namespace App\Modules\Domains\Http\Requests;

use App\Enums\FieldType;
use App\Modules\Domains\Drivers\Registrars\RegistrarManager;
use App\Support\Integrations\KeepsSecretsOutOfOldInput;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Registrar credentials: a failed validation never flashes a password-type setting (or an undeclared one) into the
 * session (security standards §6).
 */
class RegistrarAccountRequest extends FormRequest
{
    use KeepsSecretsOutOfOldInput;

    public function authorize(): bool
    {
        return $this->user()?->hasPermission('domains.registrars.manage') ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'is_active' => ['required', 'boolean'],
            'test_mode' => ['required', 'boolean'],
            'settings' => ['nullable', 'array'],
            'settings.*' => ['nullable', 'max:1000'],
        ];

        foreach (app(RegistrarManager::class)->fields((string) $this->route('registrar')) as $field) {
            $rules["settings.{$field->key}"] = match ($field->type) {
                FieldType::Number => ['nullable', 'integer', 'min:0', 'max:1000000'],
                FieldType::Select => ['nullable', Rule::in(array_map('strval', array_keys($field->options)))],
                FieldType::Boolean => ['nullable', 'boolean'],
                default => ['nullable', 'string', 'max:1000'],
            };
        }

        return $rules;
    }

    /**
     * Every submitted setting except the driver's declared non-password fields.
     *
     * @return list<string>
     */
    protected function secretInputPaths(): array
    {
        $fields = app(RegistrarManager::class)->fields((string) $this->route('registrar'));
        $public = array_values(array_map(fn ($field): string => $field->key, array_filter($fields, fn ($field): bool => ! $field->isSecret())));
        $submitted = array_map('strval', array_keys((array) $this->input('settings', [])));

        return array_values(array_map(fn (string $setting): string => "settings.{$setting}", array_diff($submitted, $public)));
    }
}
