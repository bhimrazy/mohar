<?php

namespace App\Modules\Services\Drivers\Provisioning;

use App\Modules\Services\Models\Server;
use App\Modules\Services\Models\Service;

/**
 * Everything a driver needs to act on one service. Server credentials are decrypted lazily
 * through the Server model's encrypted casts when the driver reads them.
 */
final readonly class ServiceContext
{
    /**
     * @param  array{id: int, name: string, email: string}  $client
     * @param  array<string, mixed>  $productConfig  product driver_config
     * @param  array<int|string, mixed>  $configOptions
     * @param  array<string, mixed>  $customFields  by module key (Catalog field module_key, else its name)
     * @param  list<string>  $secretValues  values of password-type custom fields (redacted from logs)
     */
    public function __construct(
        public Service $service,
        public ?Server $server,
        public array $client,
        public array $productConfig = [],
        public array $configOptions = [],
        public array $customFields = [],
        public array $secretValues = [],
    ) {}

    /**
     * Values that must never appear in logs.
     *
     * @return list<string>
     */
    public function secrets(): array
    {
        return array_values(array_filter([
            ...($this->server?->secrets() ?? []),
            ...$this->secretValues,
            $this->service->password,
        ], fn (?string $value): bool => $value !== null && $value !== ''));
    }
}
