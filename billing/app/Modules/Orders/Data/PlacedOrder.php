<?php

namespace App\Modules\Orders\Data;

use App\Models\User;
use App\Modules\Orders\Models\Order;

final readonly class PlacedOrder
{
    public function __construct(
        public Order $order,
        public bool $duplicate = false,
        public ?User $registeredUser = null,
    ) {}
}
