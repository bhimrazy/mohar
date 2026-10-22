<?php

namespace App\Data\Domains\Registrar;

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

    /**
     * Plain-array form for caches (objects must not be cached: cache.serializable_classes is false).
     *
     * @return array{domain: string, status: string, is_premium: bool, premium_register_cost: int|null, premium_renew_cost: int|null}
     */
    public function toArray(): array
    {
        return [
            'domain' => $this->domain,
            'status' => $this->status,
            'is_premium' => $this->isPremium,
            'premium_register_cost' => $this->premiumRegisterCost,
            'premium_renew_cost' => $this->premiumRenewCost,
        ];
    }

    /**
     * @param  array{domain: string, status: string, is_premium?: bool, premium_register_cost?: int|null, premium_renew_cost?: int|null}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['domain'], $data['status'], $data['is_premium'] ?? false, $data['premium_register_cost'] ?? null, $data['premium_renew_cost'] ?? null);
    }

    public function isAvailable(): bool
    {
        return $this->status === 'available';
    }
}
