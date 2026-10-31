<?php

namespace App\Modules\Billing\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Staff settle a payment attempt in review by hand, with the amount and reference read in the provider's portal.
 */
class ResolvePaymentAttemptRequest extends FormRequest
{
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
            'outcome' => ['required', 'in:paid,not_paid'],
            'provider_reference' => ['required_if:outcome,paid', 'nullable', 'string', 'max:191'],
            'amount' => ['required_if:outcome,paid', 'nullable', 'string', 'regex:/^\d{1,3}(,?\d{3}){0,3}(\.\d{1,2})?$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['amount.regex' => __('Enter the amount as the portal shows it, for example 1130.50.')];
    }
}
