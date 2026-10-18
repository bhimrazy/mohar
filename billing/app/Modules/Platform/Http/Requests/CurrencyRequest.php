<?php

namespace App\Modules\Platform\Http\Requests;

use App\Modules\Core\Models\Currency;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CurrencyRequest extends FormRequest
{
    /**
     * Creating a currency sets its starting rate, so it also needs the sensitive rates permission (and the route a
     * recent password); editing display settings needs only core.currencies.manage.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null || ! $user->hasPermission('core.currencies.manage')) {
            return false;
        }

        return $this->route('currency') instanceof Currency || $user->hasPermission('core.currencies.rates');
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['code' => strtoupper((string) $this->input('code'))]);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['decimals.in' => __('Only currencies with 2 decimal places are supported for now.')];
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $currency = $this->route('currency');

        return [
            'code' => ['required', 'string', 'size:3', 'alpha', Rule::unique('currencies', 'code')->ignore($currency instanceof Currency ? $currency->id : null)],
            'name' => ['required', 'string', 'max:100'],
            'prefix' => ['nullable', 'string', 'max:10'],
            'suffix' => ['nullable', 'string', 'max:10'],
            'decimals' => ['required', 'integer', Rule::in(Currency::DECIMALS)],
            'format' => ['required', Rule::in(Currency::FORMATS)],
            // The starting rate is set on create; later changes go through the rates form (core.currencies.rates).
            'exchange_rate' => $currency instanceof Currency ? ['prohibited'] : ['required', 'numeric', 'gt:0', 'max:1000000000', 'decimal:0,12'],
        ];
    }
}
