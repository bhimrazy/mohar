<?php

namespace App\Data\Domains\Registrar;

use Carbon\CarbonImmutable;

/**
 * An existing registrar order a driver accepted as the one for a domain (LinksExistingOrders).
 */
final readonly class LinkedOrder
{
    /**
     * @param  array<string, string|int>  $remote  the registrar ids to record (order id)
     * @param  'active'|'pending'  $status  whether the registrar holds the registration as live
     */
    public function __construct(
        public array $remote,
        public string $status,
        public ?CarbonImmutable $expiryDate = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['remote' => $this->remote, 'status' => $this->status, 'expiry_date' => $this->expiryDate?->toDateString()];
    }
}
