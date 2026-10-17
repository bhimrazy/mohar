<?php

namespace App\Modules\Themes\Http\Requests;

use App\Modules\Themes\Support\ThemeManager;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AppearanceSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('themes.manage') ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $themes = app(ThemeManager::class);
        $exists = function (string $attribute, mixed $value, \Closure $fail) use ($themes): void {
            if (! is_string($value) || ! $themes->exists($value)) {
                $fail('Choose an installed theme.');
            }
        };

        return [
            'company_name' => ['required', 'string', 'max:100'],
            'tagline' => ['nullable', 'string', 'max:120'],
            'default_admin' => ['required', 'string', $exists],
            'default_client' => ['required', 'string', $exists],
            'default_public' => ['required', 'string', $exists],
            'density' => ['required', Rule::in(ThemeManager::DENSITIES)],
            'allow_user_choice' => ['required', 'boolean'],
        ];
    }
}
