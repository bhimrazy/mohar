<?php

namespace App\Modules\Platform\Http\Requests;

use App\Http\Middleware\RequireRecentPasswordConfirmation;
use App\Modules\Platform\Services\SettingsCatalog;
use App\Support\Integrations\KeepsSecretsOutOfOldInput;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SettingsRequest extends FormRequest
{
    use KeepsSecretsOutOfOldInput;

    public function authorize(SettingsCatalog $catalog): bool
    {
        $user = $this->user();

        return $user !== null && $catalog->canEdit($user, (string) $this->route('section'));
    }

    /**
     * Sensitive sections (security) need a recent password confirmation, checked before the input is validated, as
     * the password.recent middleware does for other routes.
     *
     * @throws ValidationException
     */
    protected function prepareForValidation(): void
    {
        if (app(SettingsCatalog::class)->requiresConfirmation((string) $this->route('section')) && ! RequireRecentPasswordConfirmation::recentlyConfirmed($this)) {
            $refusal = ValidationException::withMessages([RequireRecentPasswordConfirmation::ERROR_KEY => __('Confirm your password to continue.')]);

            // Back with the error only, never the old input: the section may hold secrets (ADR 0018 Amendment).
            if (! $this->expectsJson()) {
                $refusal->response = back()->withErrors($refusal->errors());
            }

            throw $refusal;
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(SettingsCatalog $catalog): array
    {
        return $catalog->rules((string) $this->route('section'));
    }

    /**
     * Every submitted value except the section's declared non-secret settings: a `secret: true` setting, and anything
     * undeclared, never reaches the flashed old input.
     *
     * @return list<string>
     */
    protected function secretInputPaths(): array
    {
        $public = collect(app(SettingsCatalog::class)->section((string) $this->route('section'))['settings'] ?? [])
            ->reject(fn ($definition): bool => $definition->secret)->map(fn ($definition): string => $definition->key)->all();

        return array_values(array_filter(array_keys(Arr::dot($this->all())), fn (string $path): bool => ! collect($public)
            ->contains(fn (string $key): bool => $path === $key || Str::startsWith($path, $key.'.'))));
    }

    /**
     * Use the settings' labels in error messages.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $catalog = app(SettingsCatalog::class);

        return collect($catalog->section((string) $this->route('section'))['settings'] ?? [])
            ->mapWithKeys(fn ($definition): array => [$definition->key => mb_strtolower($definition->label), $definition->key.'.*' => mb_strtolower($definition->label)])
            ->all();
    }
}
