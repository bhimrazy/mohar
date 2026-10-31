<?php

namespace App\Modules\Catalog\Http\Requests\Concerns;

use App\Contracts\Services\ProvisioningDrivers;
use App\Http\Middleware\RequireRecentPasswordConfirmation;
use App\Modules\Catalog\Services\DriverSecrets;
use Closure;
use Illuminate\Http\Exceptions\HttpResponseException;
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
                // A driver that is installed but unavailable (it failed the host's checks) takes no new bindings; a
                // product already bound to it keeps its key.
                if (is_string($value) && $value !== 'none' && $drivers->all() !== [] && ! $drivers->exists($value) && $value !== $this->storedDriver()) {
                    $fail(__('Choose an installed provisioning driver, or “none” for manual setup.'));
                }
            }],
            'driver_config' => ['present', 'array', 'max:100'],
            'driver_config.*.key' => ['required', 'string', 'max:100', 'distinct', 'regex:/^[A-Za-z0-9_.-]+$/'],
            'driver_config.*.value' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * Needs a recent password confirmation (security review 2026-10-29, L3) when a secret value is entered, or when
     * settings that hold a stored secret change in any way (#536 review, M2): another endpoint next to a kept
     * credential would send that credential there. Other saves need none, since the add-on form saves its driver
     * settings with everything else. Call it after authorize() and before validation (withValidator).
     *
     * @throws HttpResponseException
     */
    protected function ensureDriverChangeIsConfirmed(): void
    {
        $driver = $this->input('provisioning_driver');

        if (! is_string($driver) || RequireRecentPasswordConfirmation::recentlyConfirmed($this)) {
            return; // a malformed driver fails validation and saves nothing
        }

        $secrets = app(DriverSecrets::class);
        $submitted = $this->submittedDriverConfig();
        $entered = array_intersect_key(array_filter($submitted, fn (string $value): bool => $value !== ''), array_flip($secrets->secretKeys($driver, $submitted)));
        $stored = array_map(fn (mixed $value): string => is_scalar($value) ? (string) $value : (string) json_encode($value), $this->storedDriverConfig() ?? []);
        $storedSecrets = array_intersect_key(array_filter($stored, fn (string $value): bool => $value !== ''), array_flip($secrets->secretKeys((string) $this->storedDriver(), $stored)));
        $merged = $this->driverConfig();
        ksort($stored);
        ksort($merged);

        if ($entered === [] && ($storedSecrets === [] || ($driver === $this->storedDriver() && $merged === $stored))) {
            return;
        }

        throw new HttpResponseException(RequireRecentPasswordConfirmation::refusal($this));
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

        foreach ($driver['fields'] as $field) {
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
     * The driver key stored for the product/addon being edited (null when creating).
     */
    abstract protected function storedDriver(): ?string;

    /**
     * Submitted settings; a blank secret keeps its stored value (secrets are never sent to the browser), but only
     * while the driver is unchanged: another driver never inherits the previous one's credentials.
     *
     * @return array<string, string>
     */
    protected function driverConfig(): array
    {
        $driver = (string) $this->input('provisioning_driver');
        $stored = $this->storedDriver() === $driver ? $this->storedDriverConfig() : null;

        return app(DriverSecrets::class)->merge($driver, $this->submittedDriverConfig(), $stored);
    }

    /**
     * The values of submitted driver settings that are secret (declared secret by the driver, or named like a
     * credential), so a failed save never flashes them into the session (KeepsSecretsOutOfOldInput).
     *
     * @return list<string>
     */
    protected function secretInputPaths(): array
    {
        $rows = (array) $this->input('driver_config', []);
        $keys = [];

        foreach ($rows as $row) {
            if (is_array($row) && is_string($row['key'] ?? null)) {
                $keys[$row['key']] = '';
            }
        }

        $secret = app(DriverSecrets::class)->secretKeys((string) $this->input('provisioning_driver'), $keys);
        $paths = [];

        foreach ($rows as $index => $row) {
            if (! is_array($row) || ! is_string($row['key'] ?? null) || in_array($row['key'], $secret, true)) {
                $paths[] = "driver_config.{$index}.value";
            }
        }

        return $paths;
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
