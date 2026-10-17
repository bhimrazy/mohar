<?php

namespace App\Modules\Billing\Http\Requests\Client;

use App\Modules\Billing\Http\Requests\MoneyInput;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Add funds / apply credit amount from the client area.
 */
class AmountRequest extends FormRequest
{
    use MoneyInput;

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
            'amount' => ['required', self::AMOUNT, 'not_in:0,0.0,0.00'],
            'gateway' => ['nullable', 'string', 'max:50'],
        ];
    }

    public function messages(): array
    {
        return ['amount.regex' => __('Enter an amount like 25.00.')];
    }
}
