<?php

namespace App\Modules\Billing\Listeners;

use App\Events\Orders\OrderAccepted;
use App\Modules\Billing\Models\ClientFlag;

/**
 * Remembers that a client has an accepted order (gate for add funds, billing.credit.require_accepted_order).
 */
class RecordAcceptedOrder
{
    public function handle(OrderAccepted $event): void
    {
        ClientFlag::query()->upsert(
            [['client_id' => $event->clientId, 'first_order_accepted_at' => now(), 'created_at' => now(), 'updated_at' => now()]],
            ['client_id'],
            ['updated_at'],
        );
    }
}
