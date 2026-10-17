<?php

namespace App\Modules\Domains\Drivers\Registrars\Data;

final readonly class AvailabilityResult
{
    public const STATUSES = ['available', 'registered', 'reserved', 'invalid', 'unknown'];

    /**
     * @param  string  $status  available|registered|reserved|invalid|unknown (in_system = held by a domain here)
     */
    public function __construct(
        public string $domain,
        public string $status,
        public bool $isPremium = false,
        public ?int $premiumRegisterCost = null,
        public ?int $premiumRenewCost = null,
    ) {}

    public function isAvailable(): bool
    {
        return $this->status === 'available';
    }
}
