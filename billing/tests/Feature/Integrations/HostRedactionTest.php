<?php

/*
 * Host masking of ADR 0023 for #254 item 3 and #383 item 4: activity properties, fraud_checks.raw and the gateway's
 * configured credentials in gateway_logs and stored payloads. Each is checked on the stored row, with no log processor
 * involved.
 */

use App\Modules\Billing\Models\Gateway;
use App\Modules\Billing\Services\GatewayLogger;
use App\Modules\Billing\Services\GatewayManager;
use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Models\Currency;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Orders\Enums\FraudDecision;
use App\Modules\Orders\Fraud\FraudAssessment;
use App\Modules\Orders\Fraud\FraudResult;
use App\Modules\Orders\Fraud\FraudScreening;
use App\Modules\Orders\Models\FraudCheck;
use App\Modules\Orders\Models\Order;
use App\Support\Integrations\RedactionScope;
use App\Support\Integrations\RedactionSet;
use App\Support\Integrations\Redactor;

test('activity properties are masked by shape and by the call in progress, and reference keys stay readable', function () {
    $entry = RedactionScope::during(RedactionSet::of(['ActivityScope42']), fn (): ActivityLog => app(ActivityLogger::class)->log('Registrar call failed', properties: [
        'key' => 'invoice.paid',
        'error' => 'Provider said password=ACTSECRET1',
        'nested' => ['echo' => 'sent ActivityScope42'],
        'count' => 3,
    ]));

    expect($entry->fresh()->properties)->toBe([
        'key' => 'invoice.paid',
        'error' => 'Provider said password=[redacted]',
        'nested' => ['echo' => 'sent [redacted]'],
        'count' => 3,
    ]);
});

test('a fraud provider\'s answer and reasons are redacted before they are stored', function () {
    Currency::factory()->create();
    $order = Order::factory()->create();
    $result = new FraudResult(FraudDecision::Review, 40, ['Provider note: token=FRAUDTOKEN1'], ['api_key' => 'FRAUDKEY1', 'score' => 40, 'note' => 'ok']);

    app(FraudScreening::class)->record($order, new FraudAssessment('rules', $result, 12));

    $check = FraudCheck::query()->where('order_id', $order->id)->firstOrFail();
    expect($check->raw)->toBe(['api_key' => '[redacted]', 'score' => 40, 'note' => 'ok'])
        ->and($check->reasons)->toBe(['Provider note: token=[redacted]'])
        ->and($order->fresh()->fraud_payload['reasons'])->toBe(['Provider note: token=[redacted]']);
});

test('a gateway\'s configured secret is masked by value in its log and stored payloads', function () {
    Gateway::factory()->sandbox('GatewaySecret0042')->create();
    $payload = ['message' => 'signed with GatewaySecret0042', 'amount' => 5_000, 'fee_percent' => '0'];

    $entry = app(GatewayLogger::class)->log('sandbox_card', 'response', 'declined', $payload);

    expect($entry->payload)->toBe(['message' => 'signed with [redacted]', 'amount' => 5_000, 'fee_percent' => '0'])
        ->and(GatewayLogger::redact($payload, 'sandbox_card')['message'])->toBe('signed with [redacted]')
        ->and(GatewayLogger::redact($payload, 'other_gateway')['message'])->toBe('signed with GatewaySecret0042');
});

test('a gateway driver call runs with the gateway\'s secrets in scope, so an exception reported after it is masked by value', function () {
    $gateway = Gateway::factory()->sandbox('GatewaySecret0043')->create();
    $gateways = app(GatewayManager::class);

    try {
        $gateways->during($gateways->config($gateway), fn () => throw new RuntimeException('Provider echoed GatewaySecret0043'));
    } catch (RuntimeException $exception) {
        app(Redactor::class)->maskThrowable($exception);
    }

    expect($exception->getMessage())->toBe('Provider echoed [redacted]')
        ->and(RedactionScope::current()->isEmpty())->toBeTrue();
});

test('activity properties named only for a credential are withheld', function () {
    $entry = app(ActivityLogger::class)->log('Settings changed', properties: [
        'Password' => 'ACTPW1',
        'api_key' => ['v' => 'ACTKEY1'],
        'one_time_password' => true,
    ]);

    expect($entry->fresh()->properties)->toBe(['Password' => '[redacted]', 'api_key' => '[redacted]', 'one_time_password' => true]);
});
