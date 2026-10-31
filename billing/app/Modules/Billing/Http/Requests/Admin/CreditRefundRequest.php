<?php

namespace App\Modules\Billing\Http\Requests\Admin;

use App\Modules\Billing\Http\Requests\MoneyInput;
use App\Modules\Billing\Services\GatewayManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Pay account credit out (ADR 0024 §5a): recorded as made outside, or through the gateway of an earlier payment. */
class CreditRefundRequest extends FormRequest
{
    use MoneyInput;

    public function authorize(): bool
    {
        $user = $this->user();

        return ($user?->hasPermission('billing.credit.refund') ?? false)
            && ($this->input('mode') !== 'gateway' || $user->hasPermission('billing.refunds.manage'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'client_id' => ['required', 'integer', Rule::exists('clients', 'id')],
            'mode' => ['required', Rule::in(['recorded', 'gateway'])],
            'amount' => ['required', self::AMOUNT, 'not_in:0,0.0,0.00'],
            'method' => ['required_if:mode,recorded', 'nullable', 'string', Rule::in(array_keys(app(GatewayManager::class)->drivers()))],
            'reference' => ['required_if:mode,recorded', 'nullable', 'string', 'max:150'],
            'payment_id' => ['required_if:mode,gateway', 'nullable', 'integer'],
            'description' => ['nullable', 'string', 'max:255'],
        ];
    }
}
