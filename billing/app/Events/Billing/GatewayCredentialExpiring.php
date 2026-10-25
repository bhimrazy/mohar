<?php

namespace App\Events\Billing;

use App\Contracts\Events\DomainEvent;
use App\Support\Events\IdentifiesOccurrence;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A credential an active gateway needs (so far a FieldType::Certificate setting) expires soon or has expired. Raised
 * by Billing's daily check once per warning stage (billing.gateways.credential_warning_days, then once on expiry);
 * Notifications emails staff. Never carries the credential.
 */
final class GatewayCredentialExpiring implements DomainEvent
{
    use Dispatchable, IdentifiesOccurrence;

    /**
     * @param  string  $gateway  the gateway (driver) key
     * @param  string  $gatewayName  the name staff gave the gateway
     * @param  string  $credential  the setting key
     * @param  string  $expiresAt  ISO 8601 instant, UTC
     * @param  int  $daysLeft  company-calendar days until the expiry date; 0 or less once it has expired
     */
    public function __construct(
        public string $gateway,
        public string $gatewayName,
        public string $credential,
        public string $credentialLabel,
        public string $expiresAt,
        public int $daysLeft,
    ) {}
}
