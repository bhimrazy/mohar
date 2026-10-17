<?php

namespace App\Events\Orders;

use Illuminate\Foundation\Events\Dispatchable;

final class OrderMarkedFraud
{
    use Dispatchable;

    public function __construct(
        public int $orderId,
        public int $clientId,
    ) {}
}
