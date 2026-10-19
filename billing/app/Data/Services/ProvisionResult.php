<?php

namespace App\Data\Services;

/**
 * Answer of ServiceRegistry::provision(). `pending` means nothing failed: the create runs on the queue (a driver that
 * needs longer than a page request, a retry, or another action holding the service). Callers show it as queued.
 */
final readonly class ProvisionResult
{
    public function __construct(
        public bool $success,
        public ?string $message = null,
        public bool $pending = false,
    ) {}
}
