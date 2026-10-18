<?php

namespace App\Events\Domains;

use App\Contracts\Events\DomainEvent;
use App\Support\Events\IdentifiesOccurrence;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * One aggregated expiry reminder per client and day, listing every domain reminded in that run
 * (Notifications renders a single email from it; DomainExpiring is still dispatched per domain).
 */
final class DomainExpiryDigest implements DomainEvent
{
    use Dispatchable, IdentifiesOccurrence;

    /**
     * @param  list<array{domain_id: int, domain: string, expiry_date: string, days_left: int}>  $domains
     */
    public function __construct(
        public int $clientId,
        public array $domains,
        public string $runDate,
    ) {}
}
