<?php

namespace App\Modules\Catalog\Http\Requests\Concerns;

use App\Contracts\Services\ProvisioningDrivers;
use App\Modules\Catalog\Services\DriverSecrets;
use Closure;
use Illuminate\Validation\Validator;

/**
 * Provisioning driver key + driver settings ([{key, value}] pairs stored as a JSON object).
 *
 * When the Services module reports installed drivers, the key must be one of them (or "none") and the
 * driver's required settings must be filled in; otherwise only the key format is checked.
 */
trait ValidatesDriverBinding
{
    /**
     * @return array<string, array<mixed>>
     */
    protected function driverRules(ProvisioningDrivers $drivers): array
    {
        return [
            'provisioning_driver' => ['required', 'string', 'max:50', 'regex:/^[a-z0-9_-]+$/', function (string $attribute, mixed $value, Closure $fail) use ($drivers): void {
                if (is_string($value) && $value !== 'none' && $drivers->all() !== [] && ! $drivers->exists($value)) {
                    $fail(__('Choose an installed provisioning driver, or “none” for manual setup.'));
                }
            }],
            'driver_config' => ['present', 'array', 'max:100'],
            'driver_config.*.key' => ['required', 'string', 'max:100', 'distinct', 'regex:/^[A-Za-z0-9_.-]+$/'],
            'driver_config.*.value' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function driverMessages(): array
    {
        return [
            'provisioning_driver.regex' => __('Use a lowercase driver key such as log or none.'),
            'driver_config.*.key.required' => __('Name this setting or remove it.'),
            'driver_config.*.key.regex' => __('Setting names may only contain letters, numbers, dots, dashes and underscores.'),
            'driver_config.*.key.distinct' => __('Each setting name can only be used once.'),
        ];
    }

    protected function validateRequiredDriverFields(Validator $validator, ProvisioningDrivers $drivers): void
    {
        $key = $this->input('provisioning_driver');
        $driver = collect($drivers->all())->firstWhere('key', $key);

        if ($driver === null || $validator->errors()->has('provisioning_driver')) {
            return;
        }

        $config = $this->driverConfig();

        foreach ($driver['config_fields'] as $field) {
            if ($field['required'] && trim($config[$field['key']] ?? '') === '') {
                $validator->errors()->add("driver_settings.{$field['key']}", __('Enter :label.', ['label' => $field['label']]));
            }
        }
    }

    /**
     * Settings currently stored for the product/addon being edited (null when creating).
     *
     * @return array<string, mixed>|null
     */
    abstract protected function storedDriverConfig(): ?array;

    /**
     * Submitted settings; a blank secret keeps its stored value (secrets are never sent to the browser).
     *
     * @return array<string, string>
     */
    protected function driverConfig(): array
    {
        return app(DriverSecrets::class)->merge((string) $this->input('provisioning_driver'), $this->submittedDriverConfig(), $this->storedDriverConfig());
    }

    /**
     * @return array<string, string>
     */
    private function submittedDriverConfig(): array
    {
        $config = [];

        foreach ((array) $this->input('driver_config', []) as $row) {
            if (is_array($row) && is_string($row['key'] ?? null) && $row['key'] !== '') {
                $config[$row['key']] = is_scalar($row['value'] ?? null) ? (string) $row['value'] : '';
            }
        }

        return $config;
    }
}
