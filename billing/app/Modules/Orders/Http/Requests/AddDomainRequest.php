<?php

namespace App\Modules\Orders\Http\Requests;

use App\Modules\Orders\Services\DomainGuard;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class AddDomainRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'domain' => ['required', 'string', 'max:253', function (string $attribute, mixed $value, Closure $fail): void {
                if (! DomainGuard::isValidName((string) $value)) {
                    $fail(__('Enter a valid domain name, for example example.com.'));
                }
            }],
            'action' => ['required', 'in:register,transfer'],
            'years' => ['required', 'integer', 'min:1', 'max:10'],
            'epp_code' => ['nullable', 'string', 'max:100'],
            'id_protection' => ['boolean'],
        ];
    }

    /**
     * @return array{domain: string, action: string, years: int, epp_code?: ?string, id_protection?: bool}
     */
    public function domainInput(): array
    {
        return [
            'domain' => $this->string('domain')->trim()->lower()->toString(),
            'action' => $this->string('action')->toString(),
            'years' => $this->integer('years'),
            'epp_code' => $this->filled('epp_code') ? $this->string('epp_code')->toString() : null,
            'id_protection' => $this->boolean('id_protection'),
        ];
    }
}
