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
     * Secret values this request carries (the transfer code), which the host adds to its redaction set for the
     * call, so a driver that echoes them is masked like the account's credentials.
     *
     * @return list<string>
     */
    public function secrets(): array
    {
        return $this->eppCode === null || $this->eppCode === '' ? [] : [$this->eppCode];
    }

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
