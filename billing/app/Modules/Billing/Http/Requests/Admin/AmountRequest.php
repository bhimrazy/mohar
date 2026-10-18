<?php

namespace App\Modules\Billing\Http\Requests\Admin;

use App\Modules\Billing\Http\Requests\MoneyInput;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Apply/remove credit on an invoice.
 */
class AmountRequest extends FormRequest
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
        return ['amount' => ['required', self::AMOUNT, 'not_in:0,0.0,0.00']];
    }
}
