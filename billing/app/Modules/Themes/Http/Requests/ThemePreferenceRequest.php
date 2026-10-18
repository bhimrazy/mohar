<?php

namespace App\Modules\Themes\Http\Requests;

use App\Modules\Themes\Support\ThemeManager;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ThemePreferenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->is_staff && app(ThemeManager::class)->allowsUserChoice();
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $themes = app(ThemeManager::class);

        return [
            'theme' => ['nullable', 'string', function (string $attribute, mixed $value, \Closure $fail) use ($themes): void {
                if (! is_string($value) || ! $themes->exists($value)) {
                    $fail('Choose an installed theme.');
                }
            }],
            'density' => ['nullable', Rule::in(ThemeManager::DENSITIES)],
        ];
    }
}
