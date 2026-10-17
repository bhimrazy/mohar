<?php

namespace App\Modules\Domains\Http\Requests;

use App\Modules\Domains\Support\DomainName;
use App\Modules\Domains\Support\Nameservers;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class NameserversRequest extends DomainAreaRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['nameservers' => Nameservers::normalise((array) $this->input('nameservers', []))]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'nameservers' => ['required', 'array', 'min:'.Nameservers::MIN, 'max:'.Nameservers::MAX],
            'nameservers.*' => ['required', 'string', 'max:253', function (string $attribute, mixed $value, Closure $fail): void {
                if (! DomainName::isValidHostname((string) $value)) {
                    $fail('Enter a valid hostname, e.g. ns1.example.com.');
                }
            }],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'nameservers.min' => 'Enter at least 2 different nameservers.',
            'nameservers.max' => 'Enter at most 5 nameservers.',
        ];
    }

    /**
     * @return list<string>
     */
    public function nameservers(): array
    {
        return array_values(array_map('strval', (array) $this->validated('nameservers')));
    }
}
