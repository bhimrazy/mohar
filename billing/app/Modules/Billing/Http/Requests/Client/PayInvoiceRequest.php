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
            'pay_method_id' => ['nullable', 'integer'],
            'token' => ['nullable', 'string', 'max:2000'],
            'save_card' => ['boolean'],
        ];
    }
}
