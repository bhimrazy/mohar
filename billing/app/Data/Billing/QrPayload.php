<?php

namespace App\Data\Billing;

use Carbon\CarbonImmutable;

/**
 * Show a QR the customer scans with their wallet app (Fonepay dynamic QR); completion is found by lookup.
 */
final readonly class QrPayload
{
    public function __construct(
        public string $payload,
        public CarbonImmutable $expiresAt,
        public ?string $providerRef = null,
    ) {}
}
