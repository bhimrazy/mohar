<?php

namespace App\Data\Domains;

final readonly class DomainDraft
{
    /**
     * @param  'register'|'transfer'  $action
     * @param  list<string>  $nameservers
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
        public ?string $eppCode = null,
        public array $nameservers = [],
        public bool $idProtection = false,
    ) {}
}
