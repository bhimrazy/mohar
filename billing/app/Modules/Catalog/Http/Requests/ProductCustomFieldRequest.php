<?php

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Catalog\Enums\CustomFieldType;
use App\Modules\Catalog\Enums\QuantityMode;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductCustomField;
use App\Modules\Catalog\Support\RegexMatcher;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ProductCustomFieldRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('catalog.products.manage') ?? false;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($options = $this->input('options'))) {
            $this->merge(['options' => array_values(array_filter(array_map('trim', explode(',', $options)), fn (string $option): bool => $option !== ''))]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            // The module key is how a provisioning driver reads the field (PriceBook::moduleInputs()): one per product.
            'module_key' => ['nullable', 'string', 'max:100', 'regex:/^[a-z0-9_.-]+$/i', Rule::unique('product_custom_fields', 'module_key')
                ->where('product_id', $this->productId())
                ->ignore($this->route('field') instanceof ProductCustomField ? $this->route('field')->id : null)],
            'type' => ['required', Rule::enum(CustomFieldType::class)],
            'options' => ['nullable', 'array', 'required_if:type,dropdown', 'max:100'],
            'options.*' => ['string', 'max:100', 'distinct'],
            'description' => ['nullable', 'string', 'max:255'],
            'validation_regex' => ['nullable', 'string', 'max:500', function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && $value !== '' && ! RegexMatcher::isValid($value)) {
                    $fail(__('Enter a valid regular expression, e.g. /^[a-z0-9]+$/i.'));
                }
            }],
            'is_required' => ['boolean'],
            'is_admin_only' => ['boolean'],
            'show_on_order' => ['boolean'],
            'show_on_invoice' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:10000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['options.required_if' => __('List the choices for the dropdown, separated by commas.')];
    }

    /**
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            /** @var Product $product */
            $product = $this->route('product');

            if ($this->boolean('is_required') && $product->quantity_mode === QuantityMode::MultipleServices) {
                $validator->errors()->add('is_required', __('Products that create multiple services per order line can\'t have required custom fields.'));
            }
        }];
    }

    /**
     * @return array<string, mixed>
     */
    public function fieldData(): array
    {
        $data = $this->validated();

        foreach (['is_required', 'is_admin_only', 'show_on_order', 'show_on_invoice'] as $flag) {
            $data[$flag] = $this->boolean($flag);
        }

        $data['options'] = $data['type'] === CustomFieldType::Dropdown->value ? ($data['options'] ?? []) : null;
        $data['sort_order'] = $data['sort_order'] ?? 0;

        return $data;
    }

    private function productId(): ?int
    {
        $product = $this->route('product');

        return $product instanceof Product ? $product->id : null;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['module_key' => __('module key')];
    }
}
