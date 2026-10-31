<?php

use App\Modules\Billing\Services\GatewayManager;

/**
 * ADR 0030 A6: providers store the webhook and return URLs staff registered with them, so a URL never changes when a
 * driver's registration, folder or provider changes. Both are keyed by the driver key alone.
 */
test('every registered gateway keeps its webhook and return URLs', function () {
    $urls = [];

    foreach (array_keys(app(GatewayManager::class)->drivers()) as $key) {
        $urls[$key] = [route('billing.webhooks', $key, false), route('billing.gateways.return', $key, false)];
    }

    expect($urls)->toBe(array_combine(
        ['bank_transfer', 'manual', 'sandbox_card', 'cybersource', 'khalti', 'esewa', 'connectips', 'razorpay', 'stripe'],
        array_map(fn (string $key): array => ["/billing/webhooks/{$key}", "/billing/gateways/{$key}/return"], ['bank_transfer', 'manual', 'sandbox_card', 'cybersource', 'khalti', 'esewa', 'connectips', 'razorpay', 'stripe']),
    ));
});
