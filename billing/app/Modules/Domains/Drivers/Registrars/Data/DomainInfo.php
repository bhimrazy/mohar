<?php

namespace App\Modules\Domains\Drivers\Registrars\Data;

use Carbon\CarbonImmutable;

final readonly class DomainInfo
{
    /**
     * @param  list<string>  $nameservers
     * @param  string  $status  registrar status: active|pending|expired|cancelled|transferred_away|not_found
     * @param  array<string, string|int>  $remote  registrar ids, so a reconcile can backfill them
     */
    public function __construct(
        public string $status,
        public array $nameservers = [],
        public ?bool $locked = null,
        public ?CarbonImmutable $expiryDate = null,
        public ?bool $idProtection = null,
        public bool $contactChangePending = false,
        public ?CarbonImmutable $transferLockUntil = null,
        public array $remote = [],
    ) {}

    public function exists(): bool
    {
        return $this->status !== 'not_found';
    }
}
