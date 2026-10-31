<?php

namespace App\Modules\Orders\Exceptions;

use RuntimeException;

/**
 * Another process holds the item's provisioning lock: it is running "create" now, or it died and the lock has not
 * expired yet. The caller retries later or skips the item; it is never a result.
 */
class ItemBeingProvisioned extends RuntimeException
{
    public function __construct(public readonly int $orderItemId)
    {
        parent::__construct("Order item {$orderItemId} is being provisioned by another process.");
    }
}
