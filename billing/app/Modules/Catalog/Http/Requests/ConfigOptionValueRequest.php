<?php

namespace App\Modules\Catalog\Http\Requests;

use App\Enums\BillingCycle;
use App\Modules\Catalog\Http\Requests\Concerns\ValidatesPriceMatrix;
use App\Modules\Catalog\Models\ConfigOption;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ConfigOptionValueRequest extends FormRequest
{
    use ValidatesPriceMatrix;

    public function authorize(): bool
    {
        return $this->user()?->hasPermission('catalog.options.manage') ?? false;
    }

    /**
     * Option values follow the parent product's cycle, so every recurring cycle and one-time can be priced.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'label' => ['required', 'string', 'max:150'],
            'module_value' => ['nullable', 'string', 'max:150'],
            'is_hidden' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:10000'],
            ...$this->priceMatrixRules([...BillingCycle::recurring(), BillingCycle::OneTime]),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->priceMatrixMessages();
    }

    /**
     * @return array<int, \Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            fn (Validator $validator) => $this->validateDistinctPriceRows($validator),
            function (Validator $validator): void {
                $option = $this->route('option');

                if ($option instanceof ConfigOption && $option->type->hasSingleValue() && $option->values()->exists()) {
                    $validator->errors()->add('label', __('Yes/no and quantity options have a single price row. Edit the existing one instead.'));
                }
            },
        ];
    }

    /**
     * @return array{label: string, module_value: ?string, is_hidden: bool, sort_order: int, prices: list<array{currency: string, cycle: string, enabled: bool, amount: int, setup_fee: int}>}
     */
    public function valueData(): array
    {
        /** @var list<array{currency: string, cycle: string, enabled: bool, amount: int, setup_fee: int}> $prices */
        $prices = $this->validated('prices');

        return [
            'label' => (string) $this->validated('label'),
            'module_value' => $this->validated('module_value'),
            'is_hidden' => $this->boolean('is_hidden'),
            'sort_order' => (int) ($this->validated('sort_order') ?? 0),
            'prices' => $prices,
        ];
    }
}
