<?php

namespace App\Modules\Billing\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Filters of the gateway log.
 */
class GatewayLogListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('billing.gateway_log.view') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'gateway' => ['nullable', 'string', 'max:50'],
            'direction' => ['nullable', 'in:callback,request,response'],
            'invoice' => ['nullable', 'integer'],
        ];
    }
}
