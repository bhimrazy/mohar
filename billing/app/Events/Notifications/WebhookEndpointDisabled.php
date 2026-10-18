<?php

namespace App\Events\Notifications;

use App\Contracts\Events\DomainEvent;
use App\Support\Events\IdentifiesOccurrence;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An endpoint was switched off after too many consecutive failures (spec §8). Never carries the secret.
 */
final class WebhookEndpointDisabled implements DomainEvent
{
    use Dispatchable, IdentifiesOccurrence;

    public function __construct(
        public int $endpointId,
        public string $url,
        public int $consecutiveFailures,
    ) {}
}
