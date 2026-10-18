<?php

namespace App\Modules\Themes\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class BrandTextRequest extends FormRequest
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
        return ['tagline' => ['nullable', 'string', 'max:120']];
    }
}
