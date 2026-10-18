<?php

namespace App\Events\Notifications;

use App\Support\Events\IdentifiesOccurrence;
use Illuminate\Foundation\Events\Dispatchable;

final class WebhookDelivered
{
    use Dispatchable, IdentifiesOccurrence;

    public function __construct(
        public int $deliveryId,
        public int $endpointId,
        public string $eventType,
    ) {}
}
