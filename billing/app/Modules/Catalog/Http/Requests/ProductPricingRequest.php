<?php

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Catalog\Http\Requests\Concerns\ValidatesPriceMatrix;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\ConfigOptionPricingGuard;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ProductPricingRequest extends FormRequest
{
    use ValidatesPriceMatrix;

    public function authorize(): bool
    {
        return $this->user()?->hasPermission('catalog.products.manage') ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return $this->priceMatrixRules($this->product()->payment_type->cycles());
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
    public function after(ConfigOptionPricingGuard $guard): array
    {
        return [
            fn (Validator $validator) => $this->validateDistinctPriceRows($validator),
            function (Validator $validator) use ($guard): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $missing = $guard->missingPrices($this->enabledPricePairs(), array_values(array_map('intval', $this->product()->configOptionGroups()->pluck('config_option_groups.id')->all())));

                if ($missing !== []) {
                    $validator->errors()->add('prices', __('Price every configurable option for the cycles you enable, or unpriced options would be free. Missing: :missing', [
                        'missing' => implode('; ', array_slice($missing, 0, 6)).(count($missing) > 6 ? ' …' : ''),
                    ]));
                }
            },
        ];
    }

    /**
     * @return list<array{currency: string, cycle: string, enabled: bool, amount: int, setup_fee: int, manual?: bool}>
     */
    public function prices(): array
    {
        /** @var list<array{currency: string, cycle: string, enabled: bool, amount: int, setup_fee: int, manual?: bool}> $prices */
        $prices = $this->validated('prices');

        return $prices;
    }

    private function product(): Product
    {
        /** @var Product $product */
        $product = $this->route('product');

        return $product;
    }
}
