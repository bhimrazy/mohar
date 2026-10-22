<?php

namespace App\Modules\Domains\Drivers\Registrars\Data;

use App\Modules\Domains\Models\Domain;

/**
 * Everything a driver needs to register or transfer a domain.
 */
final readonly class RegistrationRequest
{
    /**
     * @param  list<string>  $nameservers
     */
    public function __construct(
        public Domain $domain,
        public int $years,
        public array $nameservers,
        public ContactSet $contacts,
        public bool $idProtection = false,
        public ?string $eppCode = null,
    ) {}

    /**
     * Loggable representation (EPP code redacted by the gateway).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'domain' => $this->domain->name,
            'years' => $this->years,
            'nameservers' => $this->nameservers,
            'id_protection' => $this->idProtection,
            'epp_code' => $this->eppCode,
            'contacts' => $this->contacts->toArray(),
        ];
    }
}
