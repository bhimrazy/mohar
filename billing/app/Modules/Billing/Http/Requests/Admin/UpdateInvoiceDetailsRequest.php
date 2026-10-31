<?php

namespace App\Modules\Billing\Http\Requests\Admin;

use App\Modules\Billing\Services\GatewayManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Invoice fields that stay editable after issue.
 */
class UpdateInvoiceDetailsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('billing.invoices.manage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'due_date' => ['sometimes', 'nullable', 'date'],
            'payment_gateway' => ['sometimes', 'nullable', Rule::in(array_keys(app(GatewayManager::class)->drivers()))],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'admin_notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }
}
