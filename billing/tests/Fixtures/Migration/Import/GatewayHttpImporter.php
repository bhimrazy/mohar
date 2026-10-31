<?php

namespace Tests\Fixtures\Migration\Import;

use App\Data\Billing\GatewayConfig;
use App\Support\Billing\GatewayHttp;

/**
 * Probe for the record-importer Arch rule: an importer that reaches a provider through the kernel's gateway HTTP
 * helper rather than the Http facade.
 */
final class GatewayHttpImporter
{
    public function upsert(): void
    {
        GatewayHttp::for('payments_stripe.http')->request(new GatewayConfig('stripe', 'Stripe'))->post('https://api.stripe.example/v1/refunds');
    }
}
