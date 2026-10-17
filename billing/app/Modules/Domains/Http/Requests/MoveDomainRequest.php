<?php

namespace App\Modules\Domains\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MoveDomainRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('domains.domains.update') ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return ['client_id' => ['required', 'integer', Rule::exists('clients', 'id')]];
    }
}
