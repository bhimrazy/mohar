<?php

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Catalog\Models\Product;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductUpgradesRequest extends FormRequest
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
        /** @var Product $product */
        $product = $this->route('product');

        return [
            'target_ids' => ['present', 'array'],
            'target_ids.*' => ['integer', 'distinct', Rule::notIn([$product->id]), Rule::exists('products', 'id')->whereNull('deleted_at')],
            'allow_config_option_upgrades' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['target_ids.*.not_in' => __('A product can\'t be an upgrade of itself.')];
    }

    /**
     * @return list<int>
     */
    public function targetIds(): array
    {
        return array_values(array_map('intval', (array) $this->validated('target_ids')));
    }
}
