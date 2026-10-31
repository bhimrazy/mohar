<?php

namespace App\Modules\Catalog\Http\Requests;

use App\Enums\BillingCycle;
use App\Modules\Catalog\Enums\AutoSetup;
use App\Modules\Catalog\Enums\FreeDomain;
use App\Modules\Catalog\Enums\PaymentType;
use App\Modules\Catalog\Enums\ProductType;
use App\Modules\Catalog\Enums\QuantityMode;
use App\Modules\Catalog\Http\Requests\Concerns\ValidatesSlug;
use App\Modules\Catalog\Models\Product;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Product details (create + the "Details" tab). Prices and relations have their own requests.
 */
class ProductRequest extends FormRequest
{
    use ValidatesSlug;

    public function authorize(): bool
    {
        return $this->user()?->hasPermission('catalog.products.manage') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->prepareSlug();

        $tlds = $this->input('free_domain_tlds');

        if (is_string($tlds)) {
            $tlds = explode(',', $tlds);
        }

        if (is_array($tlds)) {
            $this->merge(['free_domain_tlds' => array_values(array_filter(array_map(
                fn (mixed $tld): string => is_string($tld) && trim($tld) !== '' ? '.'.ltrim(strtolower(trim($tld)), '.') : '',
                $tlds,
            )))]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $product = $this->product();

        return [
            'product_group_id' => ['required', 'integer', Rule::exists('product_groups', 'id')],
            'type' => ['required', Rule::enum(ProductType::class)],
            'name' => ['required', 'string', 'max:150'],
            'slug' => [...$this->slugRules(), Rule::unique('products', 'slug')->ignore($product?->id)->withoutTrashed()],
            'tagline' => ['nullable', 'string', 'max:255'],
            'short_description' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:10000'],
            'payment_type' => ['required', Rule::enum(PaymentType::class)],
            'is_hidden' => ['boolean'],
            'is_retired' => ['boolean'],
            'is_featured' => ['boolean'],
            'is_taxable' => ['boolean'],
            'requires_domain' => ['boolean'],
            'stock_enabled' => ['boolean'],
            // Live stock is only set on create; afterwards it changes through reservations and "Adjust stock".
            'stock_quantity' => $product === null ? ['required_if_accepted:stock_enabled', 'nullable', 'integer', 'min:0', 'max:1000000'] : ['exclude'],
            'quantity_mode' => ['required', Rule::enum(QuantityMode::class)],
            'recurring_cycles_limit' => ['required', 'integer', 'min:0', 'max:1000'],
            'fixed_term_days' => ['required', 'integer', 'min:0', 'max:36500'],
            'fixed_term_email_key' => ['nullable', 'string', 'max:100'],
            'welcome_email_key' => ['nullable', 'string', 'max:100'],
            'upgrade_email_key' => ['nullable', 'string', 'max:100'],
            'auto_setup' => ['required', Rule::enum(AutoSetup::class)],
            'prorata_enabled' => ['boolean'],
            'prorata_day' => ['required', 'integer', 'between:1,31'],
            'prorata_charge_next_month_day' => ['required', 'integer', 'between:0,32'],
            'free_domain' => ['required', Rule::enum(FreeDomain::class)],
            'free_domain_cycles' => ['nullable', 'array'],
            'free_domain_cycles.*' => ['string', 'distinct', Rule::in(array_map(fn (BillingCycle $cycle): string => $cycle->value, BillingCycle::recurring()))],
            'free_domain_tlds' => ['nullable', 'array', 'max:100'],
            'free_domain_tlds.*' => ['string', 'distinct', 'max:63', 'regex:/^\.[a-z0-9-]+(\.[a-z0-9-]+)*$/'],
            'suspend_days_override' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'terminate_days_override' => ['nullable', 'integer', 'min:0', 'max:3650'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...$this->slugMessages(),
            'stock_quantity.required_if_accepted' => __('Enter the quantity in stock.'),
            'free_domain_tlds.*.regex' => __('Enter TLDs like .com or .co.uk.'),
            'prorata_charge_next_month_day.between' => __('Use 0 to always charge the next month, 32 to never, or a day of the month.'),
        ];
    }

    /**
     * @return array<int, \Closure(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $product = $this->product();

            if ($product === null || $this->input('quantity_mode') !== QuantityMode::MultipleServices->value) {
                return;
            }

            if ($product->configOptionGroups()->exists() || $product->customFields()->where('is_required', true)->exists()) {
                $validator->errors()->add('quantity_mode', __('Products with configurable options or required custom fields can\'t create multiple services per order line.'));
            }
        }];
    }

    /**
     * @return array<string, mixed>
     */
    public function productData(): array
    {
        $data = $this->validated();

        foreach (['is_hidden', 'is_retired', 'is_featured', 'is_taxable', 'requires_domain', 'stock_enabled', 'prorata_enabled'] as $flag) {
            $data[$flag] = $this->boolean($flag);
        }

        if ($this->product() === null) {
            $data['stock_quantity'] = (int) ($data['stock_quantity'] ?? 0);
        }
        $data['free_domain_cycles'] = $data['free_domain_cycles'] ?? [];
        $data['free_domain_tlds'] = $data['free_domain_tlds'] ?? [];

        return $data;
    }

    private function product(): ?Product
    {
        $product = $this->route('product');

        return $product instanceof Product ? $product : null;
    }
}
