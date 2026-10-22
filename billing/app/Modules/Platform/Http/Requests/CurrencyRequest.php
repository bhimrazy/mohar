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
        // Non-string input (e.g. code[]=X) is left for the string rule to reject.
        if (is_string($this->input('code'))) {
            $this->merge(['code' => strtoupper($this->input('code'))]);
        }
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'decimals.in' => __('Only currencies with 2 decimal places are supported for now.'),
            'code.in' => __('The code of an existing currency cannot be changed. To use another code, add a new currency.'),
        ];
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $currency = $this->route('currency');

        return [
            // Clients, invoices, quotes, transactions, prices and rate history refer to a currency by its code, so the
            // code is fixed once the currency exists. An update may omit it or repeat it unchanged; it is never saved.
            'code' => $currency instanceof Currency
                ? ['sometimes', 'string', Rule::in([$currency->code])]
                : ['required', 'string', 'size:3', 'alpha', Rule::unique('currencies', 'code')],
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
