<?php

namespace App\Modules\Billing\Http\Requests\Client;

use App\Modules\Core\Support\ClientContext;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Pay several invoices of the current account at once (mass pay).
 */
class PaymentBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Throws with the area named, as the client area's other billing actions do.
        app(ClientContext::class)->authorize('billing');

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'invoice_ids' => ['nullable', 'array', 'max:50'],
            'invoice_ids.*' => ['integer'],
        ];
    }
}
