<?php

namespace App\Modules\Billing\Http\Requests\Client;

use App\Modules\Billing\Drivers\Gateways\SandboxCardDriver;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Card details posted to the sandbox gateway's vault (JSON). Never stored, logged or flashed.
 */
class SandboxTokenRequest extends FormRequest
{
    /**
     * JSON only (the hosted-fields script): a form post could flash card data into the session on errors.
     */
    public function authorize(): bool
    {
        return $this->expectsJson();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'card_number' => ['required', 'string', 'max:30', function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value) || ! SandboxCardDriver::passesLuhn($value)) {
                    $fail(__('Enter a valid card number.'));
                }
            }],
            'exp_month' => ['required', 'integer', 'between:1,12'],
            'exp_year' => ['required', 'integer', 'min:'.now()->year, 'max:'.(now()->year + 20)],
        ];
    }
}
