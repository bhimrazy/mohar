<?php

namespace App\Contracts\Orders;

/**
 * The numbers of orders, so other modules show the order number instead of a database id. Implemented by Orders;
 * Core binds a null default.
 */
interface OrderNumbers
{
    /**
     * @param  list<int>  $orderIds
     * @return array<int, string> order number keyed by id; unknown ids are left out
     */
    public function numbers(array $orderIds): array;
}
