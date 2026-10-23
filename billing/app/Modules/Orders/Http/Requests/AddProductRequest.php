<?php

namespace App\Modules\Orders\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Shape of a configured product. Catalog-dependent rules (cycles, options, fields, addons) are checked
 * by AddProductToCart against PriceBook.
 */
class AddProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'cycle' => ['required', 'string', 'max:20'],
            'config_options' => ['nullable', 'array'],
            'config_options.*' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'custom_fields' => ['nullable', 'array'],
            'custom_fields.*' => ['nullable', 'string', 'max:1000'],
            'domain_mode' => ['nullable', 'string', 'in:register,transfer,existing'],
            'domain_name' => ['nullable', 'string', 'max:253'],
            'epp_code' => ['nullable', 'string', 'max:100'],
            'tld_attributes' => ['nullable', 'array'],
            'addon_ids' => ['nullable', 'array'],
            'addon_ids.*' => ['integer', 'min:1'],
        ];
    }

    /**
     * @return array{product_id: int, cycle: string, config_options?: array<int|string, mixed>, custom_fields?: array<int|string, mixed>, domain_mode?: ?string, domain_name?: ?string, epp_code?: ?string, addon_ids?: list<int|string>}
     */
    public function selection(int $productId): array
    {
        /** @var array{cycle: string, config_options?: array<int|string, mixed>, custom_fields?: array<int|string, mixed>, domain_mode?: ?string, domain_name?: ?string, epp_code?: ?string, addon_ids?: list<int|string>} $validated */
        $validated = $this->validated();

        return [...$validated, 'product_id' => $productId];
    }
}
