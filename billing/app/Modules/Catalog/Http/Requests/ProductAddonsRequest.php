<?php

namespace App\Modules\Catalog\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductAddonsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('catalog.products.manage') ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'addon_ids' => ['present', 'array'],
            'addon_ids.*' => ['integer', 'distinct', Rule::exists('addons', 'id')],
        ];
    }

    /**
     * @return list<int>
     */
    public function addonIds(): array
    {
        return array_values(array_map('intval', (array) $this->validated('addon_ids')));
    }
}
