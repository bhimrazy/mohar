<?php

namespace App\Data\Domains\Registrar;

/**
 * The registrar account a call runs against (ADR 0009 §2). Every driver method receives it first, as gateways
 * receive GatewayConfig, so a resolved driver holds no account state and one instance can serve every account.
 */
final readonly class RegistrarConfig
{
    /**
     * @param  array<string, mixed>  $settings  the account's decrypted settings, keyed by settingsFields() key
     * @param  bool  $testMode  use the registrar's sandbox and the test credentials, never the live ones
     * @param  list<string>  $secretSettingKeys  settings typed Secret or SecretText; the host masks their values
     */
    public function __construct(
        public string $key,
        #[\SensitiveParameter] public array $settings = [],
        public bool $testMode = false,
        public array $secretSettingKeys = [],
    ) {}

    public function setting(string $key, mixed $default = null): mixed
    {
        return $this->settings[$key] ?? $default;
    }

    /**
     * The configured secret values, which the host redacts from logs, errors and events.
     *
     * @return list<string>
     */
    public function secrets(): array
    {
        $values = [];

        foreach ($this->secretSettingKeys as $key) {
            if (is_string($this->settings[$key] ?? null) && $this->settings[$key] !== '') {
                $values[] = $this->settings[$key];
            }
        }

        return $values;
    }
}
