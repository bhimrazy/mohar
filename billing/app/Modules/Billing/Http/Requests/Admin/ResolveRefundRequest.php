<?php

namespace App\Modules\Billing\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Staff settle a waiting refund by hand: made at the provider (with its reference) or not.
 */
class ResolveRefundRequest extends FormRequest
{
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
            'outcome' => ['required', 'in:confirmed,not_refunded'],
            'provider_reference' => ['required_if:outcome,confirmed', 'nullable', 'string', 'max:191'],
        ];
    }
}
