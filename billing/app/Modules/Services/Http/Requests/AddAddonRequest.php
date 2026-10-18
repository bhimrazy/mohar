<?php

namespace App\Modules\Services\Http\Requests;

use App\Enums\BillingCycle;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Staff adds an addon to a service without an invoice. Money in minor units.
 */
class AddAddonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('services.services.manage') ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'addon_id' => ['nullable', 'integer', 'min:1'],
            'cycle' => ['required', Rule::enum(BillingCycle::class)],
            'recurring_amount' => ['required', 'integer', 'min:0', 'max:999999999999'],
            'setup_fee' => ['required', 'integer', 'min:0', 'max:999999999999'],
            'next_due_date' => ['nullable', 'date'],
            'status' => ['required', Rule::in(['pending', 'active'])],
            'suspend_parent_on_overdue' => ['boolean'],
        ];
    }
}
