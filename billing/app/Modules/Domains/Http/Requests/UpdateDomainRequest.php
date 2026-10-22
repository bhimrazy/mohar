<?php

namespace App\Modules\Domains\Http\Requests;

use App\Support\Domains\RegistrarDriverRegistry;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDomainRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('domains.domains.update') ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'registration_date' => ['nullable', 'date'],
            'expiry_date' => ['nullable', 'date'],
            'next_due_date' => ['required', 'date'],
            'next_invoice_date' => ['required', 'date', 'before_or_equal:next_due_date'],
            'period_years' => ['required', 'integer', 'min:1', 'max:10'],
            'first_payment_amount' => ['required', 'integer', 'min:0'],
            'recurring_amount' => ['required', 'integer', 'min:0'],
            'registrar_key' => ['nullable', 'string', Rule::in(app(RegistrarDriverRegistry::class)->keys())],
            'linked_service_id' => ['nullable', 'integer', 'min:1'],
            'auto_renew' => ['required', 'boolean'],
            'id_protection' => ['required', 'boolean'],
            'dns_management' => ['required', 'boolean'],
            'email_forwarding' => ['required', 'boolean'],
            'recalculate_recurring' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function details(): array
    {
        return collect($this->validated())->except('recalculate_recurring')->all();
    }
}
