<?php

namespace App\Modules\Catalog\Services;

use App\Contracts\Services\ProvisioningDrivers;
use App\Support\Services\ProvisioningDriverRegistry;

/**
 * Keeps provisioning secrets out of the browser. A setting is secret when the driver marks it so, or when
 * its name looks like a credential. Secret values are sent masked, and an empty submitted value keeps the
 * stored secret ("leave empty to keep"). For a driver that is registered but unavailable (it failed the host's
 * checks, so its declared fields cannot be read) every setting counts as secret: nothing is sent, nothing is wiped.
 */
class DriverSecrets
{
    private const SECRET_NAME = '/(pass(word)?|secret|token|api[_.-]?key|private[_.-]?key|credential)$/i';

    public function __construct(private ProvisioningDrivers $drivers, private ProvisioningDriverRegistry $registry) {}

    /**
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    public function secretKeys(string $driver, array $config = []): array
    {
        if ($this->registry->isRegistered($driver) && ! $this->registry->has($driver)) {
            return array_map('strval', array_keys($config));
        }

        $declared = [];

        foreach ($this->drivers->all() as $candidate) {
            if ($candidate['key'] === $driver) {
                foreach ($candidate['fields'] as $field) {
                    if ($field['secret']) {
                        $declared[] = $field['key'];
                    }
                }
            }
        }

        $named = array_filter(array_map('strval', array_keys($config)), fn (string $key): bool => preg_match(self::SECRET_NAME, $key) === 1);

        return array_values(array_unique([...$declared, ...$named]));
    }

    public function isSecretName(string $key): bool
    {
        return preg_match(self::SECRET_NAME, $key) === 1;
    }

    /**
     * Settings for the admin UI: secret values replaced by an empty string.
     *
     * @param  array<string, mixed>|null  $config
     * @return array{config: array<string, string>, secret_keys: list<string>}
     */
    public function mask(string $driver, ?array $config): array
    {
        $config ??= [];
        $secrets = $this->secretKeys($driver, $config);
        $masked = [];

        foreach ($config as $key => $value) {
            $masked[(string) $key] = in_array((string) $key, $secrets, true) ? '' : (is_scalar($value) ? (string) $value : (string) json_encode($value));
        }

        return ['config' => $masked, 'secret_keys' => array_values(array_intersect($secrets, array_map('strval', array_keys($config))))];
    }

    /**
     * Submitted settings with blank secret values replaced by the stored ones.
     *
     * @param  array<string, string>  $submitted
     * @param  array<string, mixed>|null  $stored
     * @return array<string, string>
     */
    public function merge(string $driver, array $submitted, ?array $stored): array
    {
        $stored ??= [];
        $secrets = $this->secretKeys($driver, [...$stored, ...$submitted]);

        foreach ($submitted as $key => $value) {
            if ($value === '' && in_array($key, $secrets, true) && array_key_exists($key, $stored) && is_scalar($stored[$key])) {
                $submitted[$key] = (string) $stored[$key];
            }
        }

        return $submitted;
    }
}
