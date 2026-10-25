<?php

namespace App\Data\Billing;

use App\Data\Integrations\CertificateInfo;

/**
 * A gateway's staff configuration as handed to drivers (drivers never load models).
 */
final readonly class GatewayConfig
{
    /**
     * @param  array<string, mixed>  $settings  driver settings (secrets included; never log or expose them)
     * @param  list<string>  $secretSettingKeys  settings typed Secret or SecretText; the host masks their values
     */
    public function __construct(
        public string $key,
        public string $displayName,
        #[\SensitiveParameter] public array $settings = [],
        public bool $sandbox = false,
        public ?string $convertToCurrency = null,
        public array $secretSettingKeys = [],
    ) {}

    public function setting(string $key, mixed $default = null): mixed
    {
        return $this->settings[$key] ?? $default;
    }

    /**
     * Metadata of the certificate stored for a FieldType::Certificate setting (the PEM key is setting($key)), or null
     * when none is stored.
     */
    public function certificate(string $key): ?CertificateInfo
    {
        $stored = $this->settings[CertificateInfo::SETTINGS_KEY][$key] ?? null;

        return is_array($stored) ? CertificateInfo::fromArray($stored) : null;
    }

    /**
     * The configured secret values, which the host redacts from logs, errors and stored payloads.
     *
     * @return list<string>
     */
    public function secrets(): array
    {
        $values = [];

        foreach ($this->secretSettingKeys as $key) {
            if (is_scalar($this->settings[$key] ?? null) && (string) $this->settings[$key] !== '') {
                $values[] = (string) $this->settings[$key];
            }
        }

        return $values;
    }
}
