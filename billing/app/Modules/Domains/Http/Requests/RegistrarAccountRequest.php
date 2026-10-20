<?php

namespace App\Modules\Domains\Http\Requests;

use App\Modules\Domains\Drivers\Registrars\RegistrarManager;
use App\Support\Integrations\KeepsSecretsOutOfOldInput;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

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
        return [
            'is_active' => ['required', 'boolean'],
            'test_mode' => ['required', 'boolean'],
            'settings' => ['nullable', 'array'],
            'settings.*' => ['nullable', 'max:1000'],
        ];
    }

    /**
     * Every submitted setting except the driver's declared non-password fields.
     *
     * @return list<string>
     */
    protected function secretInputPaths(): array
    {
        $registrars = app(RegistrarManager::class);
        $key = (string) $this->route('registrar');
        $public = $registrars->has($key)
            ? array_column(array_filter($registrars->driver($key)->settingsSchema(), fn (array $field): bool => $field['type'] !== 'password'), 'key')
            : [];
        $submitted = array_map('strval', array_keys((array) $this->input('settings', [])));

        return array_values(array_map(fn (string $setting): string => "settings.{$setting}", array_diff($submitted, $public)));
    }
}
