<?php

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Catalog\Enums\ConfigOptionType;
use App\Modules\Catalog\Models\ConfigOption;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ConfigOptionRequest extends FormRequest
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
            'module_key' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9_.-]+$/'],
            'type' => ['required', Rule::enum(ConfigOptionType::class)],
            'quantity_min' => ['nullable', 'integer', 'min:0', 'max:1000000', 'required_with:quantity_max'],
            'quantity_max' => ['nullable', 'integer', 'min:1', 'max:1000000', 'required_with:quantity_min', 'gte:quantity_min'],
            'quantity_step' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'is_hidden' => ['boolean'],
            'is_required' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:10000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'quantity_min.required_with' => __('Set both a minimum and a maximum, or leave both empty.'),
            'quantity_max.required_with' => __('Set both a minimum and a maximum, or leave both empty.'),
            'quantity_max.gte' => __('The maximum must be at least the minimum.'),
        ];
    }

    /**
     * @return array<int, \Closure(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $option = $this->route('option');
            $type = ConfigOptionType::tryFrom((string) $this->input('type'));

            if ($option instanceof ConfigOption && $type?->hasSingleValue() && $option->values()->count() > 1) {
                $validator->errors()->add('type', __('Yes/no and quantity options have a single price row. Delete the extra values first.'));
            }
        }];
    }

    /**
     * @return array<string, mixed>
     */
    public function optionData(): array
    {
        $data = $this->validated();
        $isQuantity = $data['type'] === ConfigOptionType::Quantity->value;

        return [
            ...$data,
            'is_hidden' => $this->boolean('is_hidden'),
            'is_required' => $this->boolean('is_required'),
            'quantity_min' => $isQuantity ? ($data['quantity_min'] ?? null) : null,
            'quantity_max' => $isQuantity ? ($data['quantity_max'] ?? null) : null,
            'quantity_step' => $isQuantity ? ($data['quantity_step'] ?? 1) : 1,
            'sort_order' => $data['sort_order'] ?? 0,
        ];
    }
}
