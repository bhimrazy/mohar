<?php

namespace App\Modules\Billing\Http\Requests\Client;

use Illuminate\Foundation\Http\FormRequest;

class StorePayMethodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'gateway' => ['required', 'string', 'max:50'],
            'token' => ['required', 'string', 'max:2000'],
            'make_default' => ['boolean'],
        ];
    }
}
