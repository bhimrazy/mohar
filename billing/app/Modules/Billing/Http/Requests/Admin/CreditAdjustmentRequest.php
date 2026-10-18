<?php

namespace App\Modules\Billing\Http\Requests\Admin;

use App\Modules\Billing\Http\Requests\MoneyInput;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreditAdjustmentRequest extends FormRequest
{
    use MoneyInput;

    public function authorize(): bool
    {
        return $this->user()?->hasPermission('billing.credit.adjust') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'client_id' => ['required', 'integer', Rule::exists('clients', 'id')],
            'direction' => ['required', Rule::in(['add', 'remove'])],
            'amount' => ['required', self::AMOUNT, 'not_in:0,0.0,0.00'],
            'description' => ['required', 'string', 'max:255'],
        ];
    }
}
