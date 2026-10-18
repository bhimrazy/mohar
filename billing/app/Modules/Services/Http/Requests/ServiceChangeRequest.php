<?php

namespace App\Modules\Services\Http\Requests;

use App\Enums\BillingCycle;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Upgrade/downgrade order (client area or staff). Authorization is done by the controller
 * (client scoping or staff permission).
 */
class ServiceChangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'product_id' => ['required', 'integer', 'min:1'],
            'cycle' => ['required', Rule::enum(BillingCycle::class)],
        ];
    }

    public function cycle(): BillingCycle
    {
        return BillingCycle::from($this->string('cycle')->toString());
    }
}
