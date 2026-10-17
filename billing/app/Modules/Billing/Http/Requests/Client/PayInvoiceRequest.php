<?php

namespace App\Modules\Billing\Http\Requests\Client;

use Illuminate\Foundation\Http\FormRequest;

class PayInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'gateway' => ['required', 'string', 'max:50'],
            'pay_method_id' => ['nullable', 'integer', 'required_without:token'],
            'token' => ['nullable', 'string', 'max:2000', 'required_without:pay_method_id'],
            'save_card' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'pay_method_id.required_without' => __('Choose a saved card or enter a new one.'),
            'token.required_without' => __('Choose a saved card or enter a new one.'),
        ];
    }
}
