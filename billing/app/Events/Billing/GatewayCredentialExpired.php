<?php

namespace App\Events\Billing;

use App\Contracts\Events\DomainEvent;
use App\Support\Events\IdentifiesOccurrence;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A credential an active gateway needs (so far a FieldType::Certificate setting) has expired: the gateway can no longer
 * sign payments. Raised once by Billing's daily check after the expiry instant; a new upload starts again. Never
 * carries the credential.
 */
final class GatewayCredentialExpired implements DomainEvent
{
    use Dispatchable, IdentifiesOccurrence;

    /**
     * @param  string  $gateway  the gateway (driver) key
     * @param  string  $gatewayName  the name staff gave the gateway
     * @param  string  $credential  the setting key
     * @param  CarbonImmutable  $expiredAt  UTC
     */
    public function __construct(
        public string $gateway,
        public string $gatewayName,
        public string $credential,
        public string $credentialLabel,
        public CarbonImmutable $expiredAt,
    ) {}
}
