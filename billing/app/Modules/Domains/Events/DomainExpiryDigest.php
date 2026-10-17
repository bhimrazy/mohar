<?php

namespace App\Modules\Domains\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * One aggregated expiry reminder per client and day, listing every domain reminded in that run
 * (Notifications renders a single email from it; DomainExpiring is still dispatched per domain).
 *
 * Module-local until promoted to App\Events\Domains by the shared-kernel owners.
 */
final class DomainExpiryDigest
{
    use Dispatchable;

    /**
     * @param  list<array{domain_id: int, domain: string, expiry_date: string, days_left: int}>  $domains
     */
    public function __construct(
        public int $clientId,
        public array $domains,
        public string $runDate,
    ) {}
}
