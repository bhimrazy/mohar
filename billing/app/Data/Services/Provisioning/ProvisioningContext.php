<?php

namespace App\Data\Services\Provisioning;

/**
 * Everything a driver may know for one call on one service. The host builds it; anything a driver needs must be
 * here, which keeps the driver's view of the system a deliberate, reviewable list.
 */
final readonly class ProvisioningContext
{
    /**
     * @param  array<string, mixed>  $productConfig  values of the driver's product config fields
     * @param  array<int|string, mixed>  $configOptions  the service's configurable option selections (option id => value id or quantity)
     * @param  array<string, mixed>  $customFields  custom field values by module key (else by field name)
     * @param  list<string>  $secretValues  values of password-type custom fields, for redaction
     */
    public function __construct(
        public ServiceSnapshot $service,
        public ?ServerConnection $server,
        public ClientSummary $client,
        public array $productConfig = [],
        public array $configOptions = [],
        public array $customFields = [],
        #[\SensitiveParameter]
        public array $secretValues = [],
    ) {}

    /**
     * Every value that must never appear in logs: server credentials, the service password, secret custom fields.
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
