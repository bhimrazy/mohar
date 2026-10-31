<?php

namespace App\Modules\Catalog\Http\Requests;

use App\Contracts\Services\ProvisioningDrivers;
use App\Modules\Catalog\Http\Requests\Concerns\ValidatesDriverBinding;
use App\Modules\Catalog\Models\Product;
use App\Support\Integrations\KeepsSecretsOutOfOldInput;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Provisioning binding. Catalog only stores these values; the Services module interprets them.
 */
class ProductProvisioningRequest extends FormRequest
{
    use KeepsSecretsOutOfOldInput, ValidatesDriverBinding;

    public function authorize(): bool
    {
        return $this->user()?->hasPermission('catalog.products.manage') ?? false;
    }

    /** After authorize(), so a login without the permission gets 403 before any password prompt. */
    public function withValidator(Validator $validator): void
    {
        $this->ensureDriverChangeIsConfirmed();
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(ProvisioningDrivers $drivers): array
    {
        return [
            ...$this->driverRules($drivers),
            'server_group_id' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<int, \Closure(Validator): void>
     */
    public function after(ProvisioningDrivers $drivers): array
    {
        return [fn (Validator $validator) => $this->validateRequiredDriverFields($validator, $drivers)];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->driverMessages();
    }

    protected function storedDriverConfig(): ?array
    {
        $product = $this->route('product');

        return $product instanceof Product ? $product->driver_config : null;
    }

    protected function storedDriver(): ?string
    {
        $product = $this->route('product');

        return $product instanceof Product ? $product->provisioning_driver : null;
    }

    /**
     * @return array{provisioning_driver: string, server_group_id: ?int, driver_config: array<string, string>}
     */
    public function provisioningData(): array
    {
        return [
            'provisioning_driver' => (string) $this->validated('provisioning_driver'),
            'server_group_id' => $this->filled('server_group_id') ? $this->integer('server_group_id') : null,
            'driver_config' => $this->driverConfig(),
        ];
    }
}
