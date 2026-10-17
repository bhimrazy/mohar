<?php

namespace App\Modules\Billing\Http\Requests\Admin;

use App\Modules\Billing\Enums\RefundMode;
use App\Modules\Billing\Http\Requests\MoneyInput;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RefundRequest extends FormRequest
{
    use MoneyInput;

    public function authorize(): bool
    {
        return $this->user()?->hasPermission('billing.refunds.manage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'amount' => ['required', self::AMOUNT, 'not_in:0,0.0,0.00'],
            'mode' => ['required', Rule::enum(RefundMode::class)],
            'reverse_effects' => ['boolean'],
            'description' => ['nullable', 'string', 'max:255'],
        ];
    }
}
