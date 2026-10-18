<?php

namespace App\Modules\Notifications\Http\Requests;

use App\Modules\Notifications\Enums\EmailCategory;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * preferences: [{scope: "owner"|"user:<id>"|"contact:<id>", categories: {invoice: true, ...}}]
 * Scoping and the last-recipient rule are enforced by PreferenceManager.
 */
class EmailPreferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'preferences' => ['required', 'array', 'max:100'],
            'preferences.*.scope' => ['required', 'string', 'regex:/^(owner|user:\d+|contact:\d+)$/'],
            'preferences.*.categories' => ['required', 'array:'.implode(',', EmailCategory::values())],
            'preferences.*.categories.*' => ['boolean'],
        ];
    }
}
