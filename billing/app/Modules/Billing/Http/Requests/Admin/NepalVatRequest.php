<?php

namespace App\Modules\Billing\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class NepalVatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('billing.tax.manage') ?? false;
    }

    /**
     * What the mode may do with the values (rules exist, start day, blockers) is decided under a lock in NepalVatMode.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'enabled_from' => ['nullable', 'date_format:Y-m-d'],
            'vat_rules' => ['present', 'array', 'max:20'],
            'vat_rules.*' => ['integer', 'min:1'],
            'export_rule' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
