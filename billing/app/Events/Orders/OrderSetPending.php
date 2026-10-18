<?php

namespace App\Events\Orders;

use App\Support\Events\IdentifiesOccurrence;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Staff cleared a fraud flag: the order and its items are pending again. Modules that reacted to
 * OrderMarkedFraud (Services/Domains item status, Catalog stock) restore their per-order state.
 */
final class OrderSetPending
{
    use Dispatchable, IdentifiesOccurrence;

    public function __construct(
        public int $orderId,
        public int $clientId,
    ) {}
}
