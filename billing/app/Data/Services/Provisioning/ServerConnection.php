<?php

namespace App\Data\Services\Provisioning;

/**
 * A server and its decrypted credentials, for one driver call. Never stored, logged or sent to the browser.
 */
final readonly class ServerConnection
{
    /**
     * @param  array<string, mixed>  $settings  values of the driver's DriverMetadata::$serverFields
     * @param  list<string>  $nameservers
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
        public array $settings = [],
        public array $nameservers = [],
    ) {}

    /**
     * Credential values, for the host to redact from logs and messages.
     *
     * @return list<string>
     */
    public function secrets(): array
    {
        return array_values(array_filter([$this->password, $this->apiToken, $this->accessHash], fn (?string $value): bool => $value !== null && $value !== ''));
    }
}
