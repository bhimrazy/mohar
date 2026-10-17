<?php

namespace App\Modules\Catalog\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class AdjustStockRequest extends FormRequest
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
            'adjustment' => ['required', 'integer', 'not_in:0', 'between:-1000000,1000000'],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'adjustment.required' => __('Enter how many units to add (e.g. 10) or remove (e.g. -2).'),
            'adjustment.integer' => __('Enter a whole number of units, e.g. 10 or -2.'),
            'adjustment.not_in' => __('Enter a number other than 0.'),
        ];
    }
}
