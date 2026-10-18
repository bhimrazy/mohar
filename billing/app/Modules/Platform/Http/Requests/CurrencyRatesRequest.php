<?php

namespace App\Modules\Platform\Http\Requests;

use App\Enums\PriceRounding;
use App\Modules\Core\Models\Currency;
use App\Support\Currencies\ExchangeRateSources;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CurrencyRatesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('core.currencies.rates') ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Currency $currency */
        $currency = $this->route('currency');
        $sources = array_keys(app(ExchangeRateSources::class)->for($currency->code, Currency::default()->code));

        return [
            'rate_source' => ['required', 'string', Rule::in([ExchangeRateSources::MANUAL, ...$sources])],
            'exchange_rate' => ['nullable', Rule::requiredIf(fn (): bool => ! $currency->is_default && $this->input('rate_source') === ExchangeRateSources::MANUAL), 'numeric', 'gt:0', 'max:9999999999', 'decimal:0,6'],
            'price_rounding' => ['required', Rule::enum(PriceRounding::class)],
            'price_buffer_percent' => ['nullable', 'numeric', 'min:0', 'max:50', 'decimal:0,2'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['rate_source.in' => __('This source does not publish a rate for :code against :default.', [
            'code' => $this->route('currency') instanceof Currency ? $this->route('currency')->code : '',
            'default' => Currency::default()->code,
        ])];
    }

    /**
     * @return array{rate_source: string, exchange_rate: ?string, price_rounding: string, price_buffer_percent: string}
     */
    public function payload(): array
    {
        return [
            'rate_source' => $this->string('rate_source')->toString(),
            'exchange_rate' => $this->filled('exchange_rate') ? $this->string('exchange_rate')->trim()->toString() : null,
            'price_rounding' => $this->string('price_rounding')->toString(),
            'price_buffer_percent' => $this->filled('price_buffer_percent') ? $this->string('price_buffer_percent')->trim()->toString() : '0',
        ];
    }
}
