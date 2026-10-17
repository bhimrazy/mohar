<?php

namespace App\Contracts\Billing;

/**
 * Enabled payment gateways for checkout/pay pages. Implemented by Billing; Core binds an empty default.
 */
interface GatewayDirectory
{
    /**
     * @return list<array{key: string, label: string}>
     */
    public function available(string $currency): array;
}
