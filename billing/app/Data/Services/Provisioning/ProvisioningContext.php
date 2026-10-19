<?php

namespace App\Data\Services\Provisioning;

use Carbon\CarbonImmutable;

/**
 * Everything a driver may know for one call on one service. The host builds it; anything a driver needs must be
 * here, which keeps the driver's view of the system a deliberate, reviewable list.
 */
final readonly class ProvisioningContext
{
    /**
     * @param  array<string, mixed>  $productConfig  values of the driver's product config fields
     * @param  array<string, mixed>  $configOptions  configurable options by module key: the selected value's module value,
     *                                               or the quantity
     * @param  array<string, mixed>  $customFields  custom field values by module key
     * @param  list<string>  $secretValues  every Secret- or SecretText-typed value in $productConfig and $customFields,
     *                                      for redaction
     * @param  CarbonImmutable|null  $deadline  when the host stops waiting for this call (the command's timeout less a
     *                                          margin); every remote request must have finished by then. Null: the
     *                                          driver applies its own budget below the host default
     * @param  CarbonImmutable|null  $unknownAttemptAt  when an earlier attempt of this command for this service, sent
     *                                                  with the same username and domain as $service, ended with an
     *                                                  unknown outcome. A driver may adopt what that attempt left
     *                                                  behind (an account created at or after it), and nothing else.
     *                                                  Null when there is no such attempt
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
        public ?CarbonImmutable $deadline = null,
        public ?CarbonImmutable $unknownAttemptAt = null,
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
