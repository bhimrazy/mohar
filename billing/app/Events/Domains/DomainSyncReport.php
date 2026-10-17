<?php

namespace App\Events\Domains;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Staff digest of a registrar sync run with at least one change or error.
 */
final class DomainSyncReport
{
    use Dispatchable;

    /**
     * @param  list<array{domain_id: int, domain: string, field: string, from: string|null, to: string|null, applied: bool}>  $changes
     * @param  list<array{domain_id: int, domain: string, error: string}>  $errors
     */
    public function __construct(
        public array $changes,
        public array $errors = [],
        public bool $notifyOnly = false,
    ) {}
}
