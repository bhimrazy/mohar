<?php

namespace App\Modules\Billing\Http\Requests\Admin;

use App\Modules\Billing\Enums\InvoiceStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filters of the invoice list.
 */
class InvoiceListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('billing.invoices.view') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in([...array_column(InvoiceStatus::cases(), 'value'), 'overdue', 'outstanding'])],
            'client' => ['nullable', 'integer'],
            'gateway' => ['nullable', 'string', 'max:50'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            // Only the invoices a cancel or a late fee was refused on (Needs attention), while they are still payable.
            'refused' => ['nullable', 'boolean'],
        ];
    }
}
