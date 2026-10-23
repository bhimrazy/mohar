<?php

namespace App\Modules\Domains\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class LinkOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return ($user?->hasPermission('domains.domains.update') ?? false) && $user->hasPermission('domains.domains.commands');
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return ['order_id' => ['required', 'string', 'regex:/^\s*[1-9]\d{0,11}\s*$/']];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'order_id.required' => 'Enter the order id from the registrar.',
            'order_id.regex' => 'Enter the order id as digits only, as shown in the registrar\'s panel.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['order_id' => 'order id'];
    }

    public function orderId(): string
    {
        return trim($this->string('order_id')->toString());
    }
}
