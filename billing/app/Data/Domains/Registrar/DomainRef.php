<?php

namespace App\Data\Domains\Registrar;

use Carbon\CarbonImmutable;

/**
 * What a registrar driver knows about one of the host's domains (ADR 0009 §2): a snapshot Domains builds for each
 * call, in place of the domain record. Drivers never read or write the host's tables.
 */
final readonly class DomainRef
{
    /**
     * @param  string  $name  ASCII (punycode), lowercase, e.g. `example.com.np`
     * @param  string  $tld  extension with its leading dot, e.g. `.com.np`
     * @param  string  $status  the host's status: pending, pending_registration, pending_transfer, active, grace,
     *                          redemption, expired, transferred_away, cancelled or fraud
     * @param  array<string, string|int>  $remote  registrar ids from earlier results (C1), e.g. `order_id`
     * @param  list<string>  $nameservers
     * @param  int  $periodYears  the registration term the client pays for
     */
    public function __construct(
        public int $id,
        public int $clientId,
        public string $name,
        public string $sld,
        public string $tld,
        public string $status,
        public ?CarbonImmutable $expiryDate,
        public string $registrarKey,
        public array $remote = [],
        public array $nameservers = [],
        public ?bool $locked = null,
        public bool $idProtection = false,
        public int $periodYears = 1,
    ) {}
}
