<?php

namespace App\Events\Orders;

use App\Support\Events\IdentifiesOccurrence;
use Illuminate\Foundation\Events\Dispatchable;

final class OrderAccepted
{
    use Dispatchable, IdentifiesOccurrence;

    public function __construct(
        public int $orderId,
        public int $clientId,
    ) {}
}
