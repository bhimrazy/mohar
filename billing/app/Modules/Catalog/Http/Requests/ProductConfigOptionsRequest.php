<?php

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Catalog\Enums\QuantityMode;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\ConfigOptionPricingGuard;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ProductConfigOptionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('catalog.products.manage') ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'group_ids' => ['present', 'array'],
            'group_ids.*' => ['integer', 'distinct', Rule::exists('config_option_groups', 'id')],
        ];
    }

    /**
     * @return array<int, \Closure(Validator): void>
     */
    public function after(ConfigOptionPricingGuard $guard): array
    {
        return [function (Validator $validator) use ($guard): void {
            if ($validator->errors()->isNotEmpty() || $this->groupIds() === []) {
                return;
            }

            /** @var Product $product */
            $product = $this->route('product');

            if ($product->quantity_mode === QuantityMode::MultipleServices) {
                $validator->errors()->add('group_ids', __('Products that create multiple services per order line can\'t have configurable options.'));

                return;
            }

            $missing = $guard->missingPrices($guard->enabledPairsOf($product), $this->groupIds());

            if ($missing !== []) {
                $validator->errors()->add('group_ids', __('These options have no price for some of the product\'s billing cycles: :missing', [
                    'missing' => implode('; ', array_slice($missing, 0, 6)).(count($missing) > 6 ? ' …' : ''),
                ]));
            }
        }];
    }

    /**
     * @return list<int>
     */
    public function groupIds(): array
    {
        return array_values(array_map('intval', (array) $this->input('group_ids', [])));
    }
}
