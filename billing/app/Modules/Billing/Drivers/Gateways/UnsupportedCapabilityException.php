<?php

namespace App\Modules\Billing\Drivers\Gateways;

use App\Modules\Billing\Exceptions\BillingException;

class UnsupportedCapabilityException extends BillingException
{
    public static function for(string $gateway, string $capability): self
    {
        return new self(__('The :gateway gateway does not support :capability.', ['gateway' => $gateway, 'capability' => str_replace('_', ' ', $capability)]));
    }
}
