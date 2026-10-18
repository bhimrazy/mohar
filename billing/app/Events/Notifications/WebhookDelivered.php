<?php

namespace App\Events\Notifications;

use Illuminate\Foundation\Events\Dispatchable;

final class WebhookDelivered
{
    use Dispatchable;

    public function __construct(
        public int $deliveryId,
        public int $endpointId,
        public string $eventType,
    ) {}
}
