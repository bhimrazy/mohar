<?php

namespace App\Modules\Catalog\Http\Requests;

use App\Enums\BillingCycle;
use App\Modules\Catalog\Http\Requests\Concerns\ValidatesPriceMatrix;
use App\Modules\Catalog\Models\ConfigOption;
use App\Modules\Catalog\Models\ConfigOptionValue;
use App\Modules\Catalog\Services\ConfigOptionPricingGuard;
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
            fn (Validator $validator) => $this->validateCoversProductCycles($validator),
        ];
    }

    /**
     * A visible value must be priced for every currency × cycle sold by products using its option group,
     * otherwise those products could no longer be quoted for that cycle.
     */
    private function validateCoversProductCycles(Validator $validator): void
    {
        $value = $this->route('value');
        $option = $value instanceof ConfigOptionValue ? $value->option : $this->route('option');

        if ($validator->errors()->isNotEmpty() || ! $option instanceof ConfigOption || $option->is_hidden || $this->boolean('is_hidden')) {
            return;
        }

        $priced = array_map(fn (array $pair): string => $pair['currency'].'|'.$pair['cycle'], $this->enabledPricePairs());
        $guard = app(ConfigOptionPricingGuard::class);
        $missing = [];

        foreach ($option->group->products()->where('is_retired', false)->orderBy('products.id')->get() as $product) {
            foreach ($guard->enabledPairsOf($product) as $pair) {
                if (! in_array($pair['currency'].'|'.$pair['cycle'], $priced, true)) {
                    $missing[] = "{$product->name} ({$pair['currency']}, {$pair['cycle']})";
                }
            }
        }

        if ($missing !== []) {
            $validator->errors()->add('prices', __('Products using this option sell cycles this value has no price for: :missing. Price them or hide the value.', [
                'missing' => implode('; ', array_slice($missing, 0, 6)).(count($missing) > 6 ? ' …' : ''),
            ]));
        }
    }

    /**
     * @return array{label: string, module_value: ?string, is_hidden: bool, sort_order: int, prices: list<array{currency: string, cycle: string, enabled: bool, amount: int, setup_fee: int, manual?: bool}>}
     */
    public function valueData(): array
    {
        /** @var list<array{currency: string, cycle: string, enabled: bool, amount: int, setup_fee: int, manual?: bool}> $prices */
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
