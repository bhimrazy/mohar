<?php

namespace App\Modules\Billing\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A client's invoicing preference: a separate invoice per renewal item, or the default.
 */
class InvoicingPreferenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('billing.credit.manage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'separate_invoices' => ['nullable', 'boolean'],
        ];
    }
}
