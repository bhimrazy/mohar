<?php

namespace App\Modules\Domains\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;

/**
 * On/off switches: registrar lock, auto-renew.
 */
class ToggleRequest extends DomainAreaRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return ['enabled' => ['required', 'boolean']];
    }
}
