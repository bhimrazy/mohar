<?php

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Catalog\Models\Addon;
use App\Modules\Catalog\Models\Product;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ProductAddonsRequest extends FormRequest
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
            'addon_ids' => ['present', 'array'],
            'addon_ids.*' => ['integer', 'distinct', Rule::exists('addons', 'id')],
        ];
    }

    /**
     * Retired addons can stay attached but can't be newly attached.
     *
     * @return array<int, \Closure(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            /** @var Product $product */
            $product = $this->route('product');
            $added = array_diff(array_map('intval', (array) $this->input('addon_ids', [])), $product->addons()->pluck('addons.id')->all());

            if ($added !== [] && Addon::query()->whereKey($added)->where('is_retired', true)->exists()) {
                $validator->errors()->add('addon_ids', __('Retired addons can\'t be added to products.'));
            }
        }];
    }

    /**
     * @return list<int>
     */
    public function addonIds(): array
    {
        return array_values(array_map('intval', (array) $this->validated('addon_ids')));
    }
}
