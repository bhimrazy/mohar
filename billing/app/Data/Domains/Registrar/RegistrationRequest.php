<?php

namespace App\Data\Domains\Registrar;

/**
 * Everything a driver needs to register or transfer a domain.
 */
final readonly class RegistrationRequest
{
    /**
     * @param  list<string>  $nameservers
     */
    public function __construct(
        public DomainRef $domain,
        public int $years,
        public array $nameservers,
        public ContactSet $contacts,
        public bool $idProtection = false,
        #[\SensitiveParameter] public ?string $eppCode = null,
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
