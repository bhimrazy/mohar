<?php

namespace App\Modules\Billing\Http\Requests\Admin;

use App\Modules\Billing\Enums\QuoteStage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filters of the quote list.
 */
class QuoteListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('billing.quotes.view') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'stage' => ['nullable', Rule::enum(QuoteStage::class)],
            'client' => ['nullable', 'integer'],
        ];
    }
}
