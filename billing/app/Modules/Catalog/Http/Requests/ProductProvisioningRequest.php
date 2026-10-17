<?php

namespace App\Modules\Catalog\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Provisioning binding. Catalog only stores these values; the Services module interprets them.
 */
class ProductProvisioningRequest extends FormRequest
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
            'provisioning_driver' => ['required', 'string', 'max:50', 'regex:/^[a-z0-9_-]+$/'],
            'server_group_id' => ['nullable', 'integer', 'min:1'],
            'driver_config' => ['present', 'array', 'max:100'],
            'driver_config.*.key' => ['required', 'string', 'max:100', 'distinct', 'regex:/^[A-Za-z0-9_.-]+$/'],
            'driver_config.*.value' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'provisioning_driver.regex' => __('Use a lowercase driver key such as cpanel or none.'),
            'driver_config.*.key.regex' => __('Setting names may only contain letters, numbers, dots, dashes and underscores.'),
            'driver_config.*.key.distinct' => __('Each setting name can only be used once.'),
        ];
    }

    /**
     * @return array{provisioning_driver: string, server_group_id: ?int, driver_config: array<string, string>}
     */
    public function provisioningData(): array
    {
        $config = [];

        foreach ((array) $this->validated('driver_config') as $row) {
            $config[(string) $row['key']] = (string) ($row['value'] ?? '');
        }

        return [
            'provisioning_driver' => (string) $this->validated('provisioning_driver'),
            'server_group_id' => $this->filled('server_group_id') ? $this->integer('server_group_id') : null,
            'driver_config' => $config,
        ];
    }
}
