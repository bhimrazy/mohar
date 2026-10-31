<?php

namespace App\Modules\Billing\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Cancel selected lines of an invoice.
 */
class CancelInvoiceLinesRequest extends FormRequest
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
            'items' => ['required', 'array', 'min:1'],
            'items.*' => ['integer'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
