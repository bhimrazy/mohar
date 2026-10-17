<?php

namespace App\Modules\Platform\Http\Requests;

use App\Modules\Platform\Http\Middleware\RequireRecentPasswordConfirmation;
use App\Modules\Platform\Services\SettingsCatalog;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

class SettingsRequest extends FormRequest
{
    public function authorize(SettingsCatalog $catalog): bool
    {
        $user = $this->user();

        return $user !== null && $catalog->canEdit($user, (string) $this->route('section'));
    }

    /**
     * Sensitive sections (security) need a recent password confirmation.
     *
     * @throws ValidationException
     */
    protected function passedValidation(): void
    {
        if (app(SettingsCatalog::class)->requiresConfirmation((string) $this->route('section')) && ! RequireRecentPasswordConfirmation::recentlyConfirmed($this)) {
            throw ValidationException::withMessages([RequireRecentPasswordConfirmation::ERROR_KEY => __('Confirm your password to continue.')]);
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
     * Use the settings' labels in error messages.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $catalog = app(SettingsCatalog::class);

        return collect($catalog->section((string) $this->route('section'))['settings'] ?? [])
            ->mapWithKeys(fn ($definition): array => [$definition->key => mb_strtolower($definition->label)])
            ->all();
    }
}
