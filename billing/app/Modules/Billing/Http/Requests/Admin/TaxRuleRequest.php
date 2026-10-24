<?php

namespace App\Modules\Billing\Http\Requests\Admin;

use App\Modules\Billing\Models\TaxRule;
use App\Support\Countries;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TaxRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('billing.tax.manage') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'country' => $this->filled('country') ? Countries::normalize((string) $this->input('country')) : null,
            'state' => $this->filled('state') ? $this->input('state') : null,
            'scope' => $this->filled('scope') && $this->input('scope') !== 'any' ? $this->input('scope') : null,
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
            'country' => ['nullable', 'string', fn (string $attribute, mixed $value, \Closure $fail) => Countries::exists((string) $value) ?: $fail('Country must be chosen from the list.')],
            'state' => ['nullable', 'string', 'max:100'],
            'scope' => ['nullable', Rule::in([TaxRule::HOME_STATE, TaxRule::OTHER_STATE])],
            'rate' => ['required', 'numeric', 'min:0', 'max:100', 'regex:/^\d{1,3}(\.\d{1,3})?$/'],
            'priority' => ['nullable', 'integer', 'min:-1000', 'max:1000'],
            'effective_from' => ['nullable', 'date'],
        ];
    }
}
