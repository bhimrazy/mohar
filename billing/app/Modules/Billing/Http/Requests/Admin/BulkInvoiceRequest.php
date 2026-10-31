<?php

namespace App\Modules\Billing\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Bulk mark paid, cancel or send a reminder from the invoice list.
 */
class BulkInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Marking paid books money, so it needs the payments permission; cancel and remind need the invoices one.
        return $this->user()?->hasPermission($this->input('action') === 'mark_paid' ? 'billing.payments.manage' : 'billing.invoices.manage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in(['mark_paid', 'cancel', 'remind'])],
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['integer'],
        ];
    }
}
