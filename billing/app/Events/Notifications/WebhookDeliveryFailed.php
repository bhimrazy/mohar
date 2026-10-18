<?php

namespace App\Events\Notifications;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * One failed delivery attempt; $abandoned when no further attempt will be made.
 */
final class WebhookDeliveryFailed
{
    use Dispatchable;

    public function __construct(
        public int $deliveryId,
        public int $endpointId,
        public string $eventType,
        public int $attempt,
        public bool $abandoned,
    ) {}
}
