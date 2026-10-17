<?php

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Catalog\Enums\QuantityMode;
use App\Modules\Catalog\Models\ConfigOptionGroup;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\ConfigOptionPricingGuard;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ConfigOptionGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('catalog.options.manage') ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'product_ids' => ['present', 'array'],
            'product_ids.*' => ['integer', 'distinct', Rule::exists('products', 'id')->whereNull('deleted_at')],
        ];
    }

    /**
     * @return array<int, \Closure(Validator): void>
     */
    public function after(ConfigOptionPricingGuard $guard): array
    {
        return [function (Validator $validator) use ($guard): void {
            $group = $this->route('option_group');

            if ($validator->errors()->isNotEmpty() || ! $group instanceof ConfigOptionGroup) {
                return;
            }

            $products = Product::query()->whereKey($this->productIds())->orderBy('id')->get();

            foreach ($products as $product) {
                if ($product->quantity_mode === QuantityMode::MultipleServices) {
                    $validator->errors()->add('product_ids', __(':product creates multiple services per order line and can\'t have configurable options.', ['product' => $product->name]));

                    continue;
                }

                $missing = $guard->missingPrices($guard->enabledPairsOf($product), [$group->id]);

                if ($missing !== []) {
                    $validator->errors()->add('product_ids', __(':product sells cycles these options aren\'t priced for: :missing', [
                        'product' => $product->name,
                        'missing' => implode('; ', array_slice($missing, 0, 4)).(count($missing) > 4 ? ' …' : ''),
                    ]));
                }
            }
        }];
    }

    /**
     * @return list<int>
     */
    public function productIds(): array
    {
        return array_values(array_map('intval', (array) $this->input('product_ids', [])));
    }

    /**
     * @return array{name: string, description: ?string, product_ids: list<int>}
     */
    public function groupData(): array
    {
        return [
            'name' => (string) $this->validated('name'),
            'description' => $this->validated('description'),
            'product_ids' => $this->productIds(),
        ];
    }
}
