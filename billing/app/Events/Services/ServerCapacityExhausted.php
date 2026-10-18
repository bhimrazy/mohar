<?php

namespace App\Events\Services;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * No active server in the product's group (or driver default) had free capacity for a new account.
 */
final class ServerCapacityExhausted
{
    use Dispatchable;

    public function __construct(
        public int $serviceId,
        public int $productId,
        public ?int $serverGroupId,
    ) {}
}
