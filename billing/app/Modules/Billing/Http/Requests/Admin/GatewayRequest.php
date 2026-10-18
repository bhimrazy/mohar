<?php

namespace App\Modules\Billing\Http\Requests\Admin;

use App\Modules\Billing\Drivers\Gateways\GatewayManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GatewayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('billing.gateways.manage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'display_name' => ['required', 'string', 'max:100'],
            'is_active' => ['boolean'],
            'show_on_order_form' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'convert_to_currency' => ['nullable', 'string', 'size:3', Rule::exists('currencies', 'code')],
            'settings' => ['array'],
            'settings.*' => ['nullable', 'string', 'max:5000'],
            'replacement' => ['nullable', 'string', Rule::in(array_keys(app(GatewayManager::class)->drivers()))],
        ];
    }
}
