<?php

namespace App\Modules\Platform\Http\Requests;

use App\Modules\Platform\Services\SettingsCatalog;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SettingsRequest extends FormRequest
{
    public function authorize(SettingsCatalog $catalog): bool
    {
        $user = $this->user();

        return $user !== null && $catalog->canEdit($user, (string) $this->route('section'));
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
