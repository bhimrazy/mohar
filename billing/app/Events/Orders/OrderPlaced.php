<?php

namespace App\Events\Orders;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched after the checkout transaction commits. On a failed fraud check OrderMarkedFraud is dispatched
 * first (ADR 0008: listeners must not assume an order between the two).
 */
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
