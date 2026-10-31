<?php

namespace App\Modules\Billing\Http\Requests\Admin;

use App\Modules\Billing\Enums\TransactionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filters of the transaction list.
 */
class TransactionListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('billing.transactions.view') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'client' => ['nullable', 'integer'],
            'gateway' => ['nullable', 'string', 'max:50'],
            'type' => ['nullable', Rule::enum(TransactionType::class)],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'review' => ['nullable', 'boolean'],
            'waiting' => ['nullable', 'boolean'],
        ];
    }
}
