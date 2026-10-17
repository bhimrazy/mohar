<?php

namespace App\Modules\Orders\Http\Requests;

use App\Enums\BillingCycle;
use App\Modules\Orders\Enums\PromotionType;
use App\Modules\Orders\Models\Promotion;
use App\Support\Money;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PromotionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('orders.promotions.manage') ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Promotion|null $promotion */
        $promotion = $this->route('promotion');

        return [
            'code' => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9_-]+$/', Rule::unique(Promotion::class, 'code')->ignore($promotion?->id)],
            'type' => ['required', Rule::enum(PromotionType::class)],
            'value' => ['required', 'numeric', 'min:0', 'max:99999999', Rule::when($this->input('type') === PromotionType::Percent->value, ['max:100'])],
            'recurring' => ['boolean'],
            'recur_count' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'applies_to_products' => ['nullable', 'array'],
            'applies_to_products.*' => ['integer', 'min:1'],
            'applies_to_addons' => ['nullable', 'array'],
            'applies_to_addons.*' => ['integer', 'min:1'],
            'applies_to_tlds' => ['nullable', 'string', 'max:500'],
            'requires_products' => ['nullable', 'array'],
            'requires_products.*' => ['integer', 'min:1'],
            'requires_existing_ok' => ['boolean'],
            'cycles' => ['nullable', 'array'],
            'cycles.*' => [Rule::enum(BillingCycle::class)],
            'starts_on' => ['nullable', 'date'],
            'expires_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'max_uses' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'apply_once' => ['boolean'],
            'once_per_client' => ['boolean'],
            'new_clients_only' => ['boolean'],
            'existing_clients_only' => ['boolean'],
            'lifetime' => ['boolean'],
            'allow_upgrades' => ['boolean'],
            'min_cart_amount' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'discount_setup_fee' => ['boolean'],
            'admin_notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => Promotion::normalizeCode($this->input('code'))]);
        }
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.regex' => __('Use letters, numbers, dashes and underscores only.'),
            'value.max' => $this->input('type') === PromotionType::Percent->value ? __('A percentage cannot exceed 100.') : __('Enter a smaller amount.'),
            'expires_on.after_or_equal' => __('The expiry date must be on or after the start date.'),
        ];
    }

    /**
     * Attributes for the promotions table.
     *
     * @return array<string, mixed>
     */
    public function promotionAttributes(): array
    {
        $tlds = array_values(array_filter(array_map(
            fn (string $tld): string => '.'.ltrim(strtolower(trim($tld)), '.'),
            explode(',', $this->string('applies_to_tlds')->toString()),
        ), fn (string $tld): bool => $tld !== '.'));

        return [
            'code' => $this->string('code')->toString(),
            'type' => $this->string('type')->toString(),
            'value' => number_format((float) $this->input('value'), 2, '.', ''),
            'recurring' => $this->boolean('recurring'),
            'recur_count' => $this->integer('recur_count'),
            'applies_to' => array_filter([
                'product_ids' => array_map(intval(...), (array) $this->input('applies_to_products', [])),
                'addon_ids' => array_map(intval(...), (array) $this->input('applies_to_addons', [])),
                'tlds' => $tlds,
            ]),
            'requires' => array_filter(['product_ids' => array_map(intval(...), (array) $this->input('requires_products', []))]),
            'requires_existing_ok' => $this->boolean('requires_existing_ok'),
            'cycles' => $this->input('cycles') ?: null,
            'starts_on' => $this->input('starts_on') ?: null,
            'expires_on' => $this->input('expires_on') ?: null,
            'max_uses' => $this->integer('max_uses'),
            'apply_once' => $this->boolean('apply_once'),
            'once_per_client' => $this->boolean('once_per_client'),
            'new_clients_only' => $this->boolean('new_clients_only'),
            'existing_clients_only' => $this->boolean('existing_clients_only'),
            'lifetime' => $this->boolean('lifetime'),
            'allow_upgrades' => $this->boolean('allow_upgrades'),
            'min_cart_amount' => $this->filled('min_cart_amount') ? Money::fromDecimal((string) $this->input('min_cart_amount'), 'XXX')->amount : null,
            'discount_setup_fee' => $this->boolean('discount_setup_fee'),
            'admin_notes' => $this->input('admin_notes'),
        ];
    }
}
