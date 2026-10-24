<?php

namespace App\Events\Services;

use App\Contracts\Events\DomainEvent;
use App\Support\Events\IdentifiesOccurrence;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A module command ended without completing. With $outcomeUnknown the provider may have acted (ADR 0026): the
 * command is waiting for a server check or staff, not failed, and listeners must not report it as a failure.
 */
final class ProvisioningFailed implements DomainEvent
{
    use Dispatchable, IdentifiesOccurrence;

    public function __construct(
        public int $serviceId,
        public int $clientId,
        public string $action,
        public string $error,
        public bool $outcomeUnknown = false,
    ) {}
}
