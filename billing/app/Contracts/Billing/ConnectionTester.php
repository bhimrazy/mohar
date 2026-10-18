<?php

namespace App\Contracts\Billing;

use App\Data\Billing\GatewayConfig;
use App\Data\Integrations\ConnectionCheck;

/**
 * Opt-in for drivers that can check their saved credentials against the provider. Shown in the admin gateway form
 * as "Test connection".
 *
 * A connection test MUST NOT move money. It MAY create an unpaid provider session that expires on its own: eSewa's
 * test books an unpaid Rs 10 session because its status API is unauthenticated, while Khalti's only looks up a
 * made-up pidx (a rejected key answers 401).
 */
interface ConnectionTester
{
    public function testConnection(GatewayConfig $gateway): ConnectionCheck;
}
