<?php

namespace App\Modules\Domains\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;

class RenewNowRequest extends DomainAreaRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return ['years' => ['nullable', 'integer', 'min:1', 'max:10']];
    }
}
