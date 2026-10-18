<?php

namespace App\Data\Services\Provisioning;

/**
 * The minimum about the service's owner a panel needs (contact email, account name). Not the client record.
 */
final readonly class ClientSummary
{
    /**
     * @param  string|null  $country  ISO 3166-1 alpha-2
     */
    public function __construct(
        public int $id,
        public string $name,
        public string $email,
        public ?string $country = null,
    ) {}
}
