<?php

namespace App\Modules\Services\Drivers\Provisioning;

/**
 * Static description of a driver: identity, server requirements and capabilities.
 */
final readonly class DriverMetadata
{
    public const CHANGE_PACKAGE = 'change_package';

    public const CHANGE_PASSWORD = 'change_password';

    public const USAGE = 'usage';

    /**
     * @param  list<string>  $capabilities  constants of this class
     * @param  list<array{key: string, label: string, type: string, required: bool, secret: bool, options?: list<string>}>  $productConfigFields
     * @param  list<array{key: string, label: string, type: string, required: bool, secret: bool, options?: list<string>}>  $serverFields
     */
    public function __construct(
        public string $key,
        public string $name,
        public bool $requiresServer,
        public ?int $defaultPort = null,
        public ?int $defaultSecurePort = null,
        public array $capabilities = [],
        public array $productConfigFields = [],
        public array $serverFields = [],
    ) {}

    public function supports(string $capability): bool
    {
        return in_array($capability, $this->capabilities, true);
    }

    /**
     * @return array{key: string, name: string, requires_server: bool, capabilities: list<string>}
     */
    public function toArray(): array
    {
        return ['key' => $this->key, 'name' => $this->name, 'requires_server' => $this->requiresServer, 'capabilities' => $this->capabilities];
    }
}
