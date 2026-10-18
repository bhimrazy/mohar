<?php

namespace App\Modules\Orders\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ApplyPromotionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'promotion_code' => ['required', 'string', 'max:50'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['promotion_code.required' => __('Enter a promotion code.')];
    }
}
