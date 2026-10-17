<?php

namespace App\Modules\Billing\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class TaxRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('billing.tax.manage') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'country' => $this->filled('country') ? strtoupper((string) $this->input('country')) : null,
            'state' => $this->filled('state') ? $this->input('state') : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'level' => ['required', 'integer', 'in:1,2'],
            'country' => ['nullable', 'string', 'size:2', 'alpha'],
            'state' => ['nullable', 'string', 'max:100'],
            'rate' => ['required', 'numeric', 'min:0', 'max:100', 'regex:/^\d{1,3}(\.\d{1,3})?$/'],
            'priority' => ['nullable', 'integer', 'min:-1000', 'max:1000'],
            'effective_from' => ['nullable', 'date'],
        ];
    }
}
