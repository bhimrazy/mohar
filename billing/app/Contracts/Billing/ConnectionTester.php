<?php

namespace App\Contracts\Billing;

use App\Data\Billing\ConnectionCheck;
use App\Data\Billing\GatewayConfig;

/**
 * Opt-in for drivers that can check their saved credentials against the provider without moving money
 * (e.g. Khalti: a lookup of a made-up pidx tells a rejected key from an accepted one). Shown in the admin
 * gateway form as "Test connection".
 */
interface ConnectionTester
{
    public function testConnection(GatewayConfig $gateway): ConnectionCheck;
}
