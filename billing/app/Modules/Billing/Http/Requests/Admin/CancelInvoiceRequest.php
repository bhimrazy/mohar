<?php

namespace App\Modules\Billing\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Cancel an invoice, with an optional note.
 */
class CancelInvoiceRequest extends FormRequest
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
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
