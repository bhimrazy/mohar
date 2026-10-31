<?php

namespace App\Modules\Catalog\Http\Requests;

use App\Contracts\Services\ProvisioningDrivers;
use App\Modules\Catalog\Enums\AddonKind;
use App\Modules\Catalog\Enums\AutoSetup;
use App\Modules\Catalog\Enums\PaymentType;
use App\Modules\Catalog\Http\Requests\Concerns\ValidatesDriverBinding;
use App\Modules\Catalog\Http\Requests\Concerns\ValidatesPriceMatrix;
use App\Modules\Catalog\Models\Addon;
use App\Support\Integrations\KeepsSecretsOutOfOldInput;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class AddonRequest extends FormRequest
{
    use KeepsSecretsOutOfOldInput, ValidatesDriverBinding, ValidatesPriceMatrix;

    public function authorize(): bool
    {
        return $this->user()?->hasPermission('catalog.addons.manage') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->ensureSecretEntryIsConfirmed();
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(ProvisioningDrivers $drivers): array
    {
        $paymentType = PaymentType::tryFrom((string) $this->input('payment_type')) ?? PaymentType::Recurring;

        return [
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:5000'],
            'kind' => ['required', Rule::enum(AddonKind::class)],
            'payment_type' => ['required', Rule::enum(PaymentType::class)],
            'is_taxable' => ['boolean'],
            'show_on_order' => ['boolean'],
            'is_hidden' => ['boolean'],
            'is_retired' => ['boolean'],
            'suspend_parent_on_overdue' => ['boolean'],
            'allow_quantity' => ['boolean'],
            'prorata_with_parent' => ['boolean'],
            'welcome_email_key' => ['nullable', 'string', 'max:100'],
            'weight' => ['nullable', 'integer', 'min:-10000', 'max:10000'],
            'auto_setup' => ['required', Rule::enum(AutoSetup::class)],
            ...$this->driverRules($drivers),
            'product_ids' => ['present', 'array'],
            'product_ids.*' => ['integer', 'distinct', Rule::exists('products', 'id')->whereNull('deleted_at')],
            ...$this->priceMatrixRules($paymentType->cycles()),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...$this->priceMatrixMessages(),
            ...$this->driverMessages(),
        ];
    }

    /**
     * @return array<int, \Closure(Validator): void>
     */
    public function after(ProvisioningDrivers $drivers): array
    {
        return [
            fn (Validator $validator) => $this->validateDistinctPriceRows($validator),
            fn (Validator $validator) => $this->validateRequiredDriverFields($validator, $drivers),
        ];
    }

    protected function storedDriverConfig(): ?array
    {
        $addon = $this->route('addon');

        return $addon instanceof Addon ? $addon->driver_config : null;
    }

    protected function storedDriver(): ?string
    {
        $addon = $this->route('addon');

        return $addon instanceof Addon ? $addon->provisioning_driver : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function addonData(): array
    {
        $data = $this->validated();

        foreach (['is_taxable', 'show_on_order', 'is_hidden', 'is_retired', 'suspend_parent_on_overdue', 'allow_quantity', 'prorata_with_parent'] as $flag) {
            $data[$flag] = $this->boolean($flag);
        }

        $data['driver_config'] = $this->driverConfig();
        $data['weight'] = (int) ($data['weight'] ?? 0);
        $data['product_ids'] = array_values(array_map('intval', $data['product_ids'] ?? []));

        return $data;
    }
}
