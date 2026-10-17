<?php

namespace App\Modules\Platform\Http\Requests;

use App\Modules\Core\Models\Currency;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CurrencyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('core.currencies.manage') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['code' => strtoupper((string) $this->input('code'))]);
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
            'exchange_rate' => ['required', 'numeric', 'gt:0', 'max:9999999999', 'decimal:0,6'],
        ];
    }
}
