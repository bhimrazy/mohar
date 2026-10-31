<?php

namespace App\Modules\Billing\Http\Requests\Admin;

use App\Modules\Billing\Enums\AttemptStatus;
use App\Modules\Billing\Models\PaymentAttempt;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filters of the payment attempt list.
 */
class PaymentAttemptListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('billing.payments.manage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['nullable', Rule::enum(AttemptStatus::class)],
            'gateway' => ['nullable', 'string', 'max:50'],
            'late' => ['nullable', 'boolean'],
            'review' => ['nullable', 'boolean'],
            'check' => ['nullable', Rule::in([PaymentAttempt::CHECK_LATE, PaymentAttempt::CHECK_CREDIT, PaymentAttempt::CHECK_REFUND, PaymentAttempt::CHECK_PORTAL_REFUND])],
        ];
    }
}
