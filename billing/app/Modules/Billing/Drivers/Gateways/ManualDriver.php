<?php

namespace App\Modules\Billing\Drivers\Gateways;

/**
 * Staff-recorded payments (cash, cheque, anything handled outside the system).
 */
class ManualDriver extends AbstractGatewayDriver
{
    public function key(): string
    {
        return 'manual';
    }

    public function name(): string
    {
        return 'Manual payment';
    }
}
