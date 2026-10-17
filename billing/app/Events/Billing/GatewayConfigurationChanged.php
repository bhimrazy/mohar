<?php

namespace App\Events\Billing;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A payment gateway's configuration changed (activation, secrets rotated, conversion currency…). Security-relevant:
 * Notifications should alert administrators. Never carries secret values.
 */
final class GatewayConfigurationChanged
{
    use Dispatchable;

    /**
     * @param  array<string, array{from: mixed, to: mixed}|string>  $changes  field => before/after, or "rotated" for secrets
     */
    public function __construct(
        public string $gateway,
        public array $changes,
        public ?int $staffId,
    ) {}
}
