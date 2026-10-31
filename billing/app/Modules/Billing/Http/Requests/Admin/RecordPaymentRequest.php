<?php

namespace App\Modules\Billing\Http\Requests\Admin;

use App\Modules\Billing\Http\Requests\MoneyInput;
use App\Modules\Billing\Services\GatewayManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordPaymentRequest extends FormRequest
{
    use MoneyInput;

    public function authorize(): bool
    {
        return $this->user()?->hasPermission('billing.payments.manage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'amount' => ['required', self::AMOUNT, 'not_in:0,0.0,0.00'],
            'gateway' => ['required', 'string', Rule::in(array_keys(app(GatewayManager::class)->drivers()))],
            'gateway_txn_id' => ['nullable', 'string', 'max:191'],
            'fee' => ['nullable', self::AMOUNT],
            'occurred_at' => ['nullable', 'date', 'before_or_equal:tomorrow'],
            'description' => ['nullable', 'string', 'max:255'],
        ];
    }
}
