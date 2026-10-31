<?php

namespace App\Modules\Billing\Http\Requests\Admin;

use App\Modules\Billing\Http\Requests\MoneyInput;
use App\Modules\Billing\Services\GatewayManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ManualTransactionRequest extends FormRequest
{
    use MoneyInput;

    public function authorize(): bool
    {
        return $this->user()?->hasPermission('billing.transactions.manage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'client_id' => ['required', 'integer', Rule::exists('clients', 'id')],
            'type' => ['required', Rule::in(['manual_income', 'manual_expense'])],
            'amount' => ['required', self::AMOUNT, 'not_in:0,0.0,0.00'],
            'fee' => ['nullable', self::AMOUNT],
            'gateway' => ['required', 'string', Rule::in(array_keys(app(GatewayManager::class)->drivers()))],
            'gateway_txn_id' => ['nullable', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:255'],
            'occurred_at' => ['required', 'date', 'before_or_equal:tomorrow'],
            'invoice_ids' => ['array', 'max:50'],
            'invoice_ids.*' => ['integer'],
        ];
    }
}
