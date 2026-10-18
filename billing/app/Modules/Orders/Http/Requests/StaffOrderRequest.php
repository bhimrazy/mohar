<?php

namespace App\Modules\Orders\Http\Requests;

use App\Enums\BillingCycle;
use App\Modules\Core\Models\Client;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Staff "place order on behalf of client": one product line with an optional price override.
 */
class StaffOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('orders.orders.create') ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'client_id' => ['required', 'integer', Rule::exists(Client::class, 'id')],
            'product_id' => ['required', 'integer', 'min:1'],
            'cycle' => ['required', Rule::enum(BillingCycle::class)],
            'domain_mode' => ['nullable', 'string', 'in:register,transfer,existing'],
            'domain_name' => ['nullable', 'string', 'max:253'],
            'epp_code' => ['nullable', 'string', 'max:100'],
            'price_override' => ['nullable', PromotionRequest::DECIMAL],
            'promotion_code' => ['nullable', 'string', 'max:50'],
            'payment_gateway' => ['required', 'string', 'max:50'],
            'skip_fraud' => ['boolean'],
            'send_invoice' => ['boolean'],
            'admin_notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['price_override.regex' => __('Enter a price like 10 or 12.50.')];
    }
}
