<?php

namespace App\Events\Orders;

use Illuminate\Foundation\Events\Dispatchable;

final class OrderPlaced
{
    use Dispatchable;

    /**
     * @param  list<array{type: string, id: int, product_id: ?int}>  $items  items created by the order: type is service|service_addon|domain
     */
    public function __construct(
        public int $orderId,
        public int $clientId,
        public ?int $invoiceId,
        public array $items,
        public ?string $referralCode,
    ) {}
}
