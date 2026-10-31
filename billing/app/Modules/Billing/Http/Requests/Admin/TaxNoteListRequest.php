<?php

namespace App\Modules\Billing\Http\Requests\Admin;

use App\Modules\Billing\Support\TaxNoteList;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Filters of the credit and debit note list.
 */
class TaxNoteListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('billing.tax_notes.view') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:50'],
            'kind' => ['nullable', 'in:credit,debit'],
            'reason' => ['nullable', 'string', 'max:30'],
            'settlement' => ['nullable', 'in:'.implode(',', array_keys(TaxNoteList::SETTLEMENTS))],
            'automatic' => ['nullable', 'in:0,1'],
            'period' => ['nullable', 'regex:/^\d{4}-\d{2}$/'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'client' => ['nullable', 'integer'],
        ];
    }
}
