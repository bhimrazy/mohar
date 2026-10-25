<?php

namespace App\Data\Domains;

final readonly class DomainDraft
{
    /**
     * @param  'register'|'transfer'  $action
     * @param  list<string>  $nameservers
     * @param  array<string, string>  $tldAttributes  answers to the registrar's extension fields (personal data)
     */
    public function __construct(
        public int $clientId,
        public string $domain,
        public string $action,
        public int $years,
        public string $currency,
        public int $recurringAmount,
        public int $firstPaymentAmount,
        public ?int $orderId = null,
        #[\SensitiveParameter] public ?string $eppCode = null,
        public array $nameservers = [],
        public bool $idProtection = false,
        #[\SensitiveParameter] public array $tldAttributes = [],
    ) {}
}
