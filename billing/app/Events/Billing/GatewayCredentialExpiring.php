<?php

namespace App\Events\Billing;

use App\Contracts\Events\DomainEvent;
use App\Support\Events\IdentifiesOccurrence;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A credential an active gateway needs (so far a FieldType::Certificate setting) expires soon. Raised by Billing's
 * daily check once per warning stage (billing.gateways.certificate_warning_days); GatewayCredentialExpired follows
 * once it has expired. Notifications emails staff. Never carries the credential.
 */
final class GatewayCredentialExpiring implements DomainEvent
{
    use Dispatchable, IdentifiesOccurrence;

    /**
     * @param  string  $gateway  the gateway (driver) key
     * @param  string  $gatewayName  the name staff gave the gateway
     * @param  string  $credential  the setting key
     * @param  CarbonImmutable  $expiresAt  UTC
     * @param  int  $daysLeft  company-calendar days until the expiry date; 0 on the expiry date itself
     */
    public function __construct(
        public string $gateway,
        public string $gatewayName,
        public string $credential,
        public string $credentialLabel,
        public CarbonImmutable $expiresAt,
        public int $daysLeft,
    ) {}
}
