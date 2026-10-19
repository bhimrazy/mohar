<?php

namespace App\Data\Services\Provisioning;

/**
 * A server and its decrypted credentials, for one driver call. Never stored, logged or sent to the browser.
 *
 * Drivers connect to $hostname, through App\Support\Integrations\OutboundAddressGuard, so certificates are checked
 * against a name. $ip is the server's main address as staff recorded it: informational, or the target only when a
 * driver documents IP-only connections (with a pinned certificate).
 */
final readonly class ServerConnection
{
    /**
     * @param  array<string, mixed>  $settings  values of the driver's DriverMetadata::$serverFields, decrypted
     * @param  list<string>  $nameservers
     * @param  list<string>  $secretSettingKeys  keys of $settings whose fields are secret-typed, for redaction
     */
    public function __construct(
        public int $id,
        public string $name,
        public string $hostname,
        public ?string $ip = null,
        public ?int $port = null,
        public bool $secure = true,
        public ?string $username = null,
        #[\SensitiveParameter]
        public ?string $password = null,
        #[\SensitiveParameter]
        public ?string $apiToken = null,
        #[\SensitiveParameter]
        public ?string $accessHash = null,
        #[\SensitiveParameter]
        public array $settings = [],
        public array $nameservers = [],
        public array $secretSettingKeys = [],
    ) {}

    /**
     * Credential and secret setting values, for the host to redact from logs and messages.
     *
     * @return list<string>
     */
    public function secrets(): array
    {
        $settings = array_map(fn (string $key): mixed => $this->settings[$key] ?? null, $this->secretSettingKeys);

        return array_values(array_filter(
            [$this->password, $this->apiToken, $this->accessHash, ...$settings],
            fn (mixed $value): bool => is_string($value) && $value !== '',
        ));
    }
}
