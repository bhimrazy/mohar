<?php

namespace App\Modules\Services\Http\Requests;

use App\Enums\BillingCycle;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Staff edit of a service. Money fields are integer minor units.
 */
class ServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('services.services.manage') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'domain' => $this->filled('domain') ? strtolower(trim((string) $this->input('domain'))) : null,
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'domain' => ['nullable', 'string', 'max:255'],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'min:8', 'max:255'],
            'dedicated_ip' => ['nullable', 'ip'],
            'server_id' => ['nullable', 'integer', Rule::exists('servers', 'id')],
            'cycle' => ['required', Rule::enum(BillingCycle::class)],
            'quantity' => ['required', 'integer', 'min:1', 'max:10000'],
            'first_payment_amount' => ['required', 'integer', 'min:0', 'max:999999999999'],
            'recurring_amount' => ['required', 'integer', 'min:0', 'max:999999999999'],
            'next_due_date' => ['nullable', 'date'],
            'next_invoice_date' => ['nullable', 'date', 'after_or_equal:next_due_date'],
            'suspend_exempt_until' => ['nullable', 'date'],
            'suspend_days_override' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'terminate_days_override' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'recurring_cycles_limit' => ['required', 'integer', 'min:0', 'max:1000'],
            'do_not_renew' => ['boolean'],
            'terminate_at_period_end' => ['boolean'],
            'payment_gateway' => ['nullable', 'string', 'max:50'],
            'admin_notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
