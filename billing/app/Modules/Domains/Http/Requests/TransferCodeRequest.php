<?php

namespace App\Modules\Domains\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;

class TransferCodeRequest extends DomainAreaRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return ['epp_code' => ['required', 'string', 'max:255']];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['epp_code.required' => 'Enter the EPP (transfer) code from your current registrar.'];
    }
}
