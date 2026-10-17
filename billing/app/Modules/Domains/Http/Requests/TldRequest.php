<?php

namespace App\Modules\Domains\Http\Requests;

use App\Modules\Domains\Drivers\Registrars\RegistrarManager;
use App\Modules\Domains\Models\Tld;
use App\Modules\Domains\Support\DomainName;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class TldRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('domains.tlds.manage') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $extension = trim((string) $this->input('extension'), " .\t\n");

        try {
            $ascii = $extension === '' ? '' : DomainName::toAscii($extension);
        } catch (InvalidArgumentException) {
            $ascii = mb_strtolower($extension);
        }

        $this->merge(['extension' => $ascii === '' ? '' : Tld::normaliseExtension($ascii)]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $tld = $this->route('tld');

        return [
            'extension' => ['required', 'string', 'max:63', Rule::unique('tlds', 'extension')->ignore($tld instanceof Tld ? $tld->id : null),
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! DomainName::isValid('x'.$value, allowIdn: false)) {
                        $fail('Enter an extension such as .com or .co.uk.');
                    }
                }],
            'registrar_key' => ['nullable', 'string', Rule::in(app(RegistrarManager::class)->keys())],
            'auto_register' => ['required', 'boolean'],
            'epp_required' => ['required', 'boolean'],
            'min_years' => ['required', 'integer', 'min:1', 'max:10'],
            'max_years' => ['required', 'integer', 'min:1', 'max:10', 'gte:min_years'],
            'allow_register' => ['required', 'boolean'],
            'allow_transfer' => ['required', 'boolean'],
            'grace_days' => ['required', 'integer', 'min:0', 'max:365'],
            'grace_fee' => ['required', 'integer', 'min:0'],
            'redemption_days' => ['required', 'integer', 'min:0', 'max:365'],
            'redemption_fee' => ['required', 'integer', 'min:0'],
            'renew_window_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'addon_dns' => ['required', 'boolean'],
            'addon_id_protection' => ['required', 'boolean'],
            'addon_email_forwarding' => ['required', 'boolean'],
            'is_spotlight' => ['required', 'boolean'],
            'badge' => ['nullable', Rule::in(Tld::BADGES)],
            'sort_order' => ['required', 'integer', 'min:0', 'max:100000'],
            'is_active' => ['required', 'boolean'],
        ];
    }
}
