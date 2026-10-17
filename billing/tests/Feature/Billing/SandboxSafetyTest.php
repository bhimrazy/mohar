<?php

use App\Contracts\Billing\GatewayRegistry;
use App\Modules\Billing\Actions\ConfigureGateway;
use App\Modules\Billing\Database\Seeders\BillingSeeder;
use App\Modules\Billing\Drivers\Gateways\GatewayManager;
use App\Modules\Billing\Drivers\Gateways\SandboxCardDriver;
use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Billing\Models\Gateway;
use App\Modules\Billing\Models\GatewayLog;
use App\Modules\Billing\Models\PaymentAttempt;
use App\Modules\Billing\Services\GatewayLogger;

require_once __DIR__.'/helpers.php';

test('the sandbox gateway is disabled in production unless explicitly forced', function () {
    app()->detectEnvironment(fn () => 'production');

    expect(SandboxCardDriver::enabled())->toBeFalse();

    config(['billing.sandbox_enabled' => true]);
    expect(SandboxCardDriver::enabled())->toBeTrue();

    app()->detectEnvironment(fn () => 'testing');
    config(['billing.sandbox_enabled' => null]);
    expect(SandboxCardDriver::enabled())->toBeTrue();
});

test('a disabled sandbox is not registered and cannot be activated', function () {
    config(['billing.sandbox_enabled' => false]);
    app()->forgetInstance(GatewayManager::class);
    app()->forgetInstance(GatewayRegistry::class);

    expect(app(GatewayRegistry::class)->has(SandboxCardDriver::KEY))->toBeFalse()
        ->and(fn () => app(ConfigureGateway::class)->handle(SandboxCardDriver::KEY, [
            'display_name' => 'Card', 'is_active' => true, 'show_on_order_form' => true, 'sort_order' => 0, 'convert_to_currency' => null,
        ], []))->toThrow(BillingException::class, 'Sandbox gateways can\'t be activated in production.');
});

test('the seeder never creates the sandbox gateway outside local and testing', function () {
    app()->detectEnvironment(fn () => 'staging');

    $this->seed(BillingSeeder::class);

    expect(Gateway::query()->where('key', SandboxCardDriver::KEY)->exists())->toBeFalse()
        ->and(Gateway::query()->where('key', 'bank_transfer')->exists())->toBeTrue();
});

test('payment attempt references are random and unguessable', function () {
    $references = collect(range(1, 50))->map(fn () => PaymentAttempt::newReference());

    expect($references->unique())->toHaveCount(50)
        ->and($references->every(fn (string $reference) => preg_match('/^[a-z2-7]{26}$/', $reference) === 1))->toBeTrue();
});

test('money-moving and security staff actions require a recent password confirmation', function () {
    actingAsStaff(['billing.*']);
    $payment = payInvoice(issueInvoice(billingClient(), [1_000]), 1_000);
    $invoice = $payment->invoice()->firstOrFail();

    $this->from(route('admin.billing.invoices.show', $invoice))
        ->post(route('admin.billing.transactions.refund', $payment), ['amount' => '10.00', 'mode' => 'record_only'])
        ->assertRedirect(route('password.confirm'));
    expect(session('url.intended'))->toBe(route('admin.billing.invoices.show', $invoice))
        ->and($payment->fresh()->refundable_remaining)->toBe(1_000);

    $this->put(route('admin.billing.gateways.update', 'bank_transfer'), ['display_name' => 'X'])->assertRedirect(route('password.confirm'));
    $this->post(route('admin.billing.tax-rules.store'), ['name' => 'VAT', 'level' => 1, 'rate' => '5'])->assertRedirect(route('password.confirm'));

    $this->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('admin.billing.transactions.refund', $payment), ['amount' => '10.00', 'mode' => 'record_only'])
        ->assertRedirect();
    expect($payment->fresh()->refundable_remaining)->toBe(0);
});

test('unverified webhook bodies are never stored, only fingerprinted', function () {
    sandboxGateway();
    $body = json_encode(['type' => 'payment.succeeded', 'junk' => str_repeat('x', 50_000)]);

    $this->call('POST', route('billing.webhooks', 'sandbox_card'), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_SANDBOX_SIGNATURE' => 'bad'], $body)->assertForbidden();

    $entry = GatewayLog::query()->where('result', 'invalid signature')->firstOrFail();
    expect($entry->payload)->toHaveKeys(['ip', 'length', 'sha256'])->not->toHaveKey('junk')
        ->and(strlen((string) json_encode($entry->payload)))->toBeLessThan(500);
});

test('the gateway log caps payloads and masks card numbers inside free text', function () {
    $entry = app(GatewayLogger::class)->log('sandbox_card', 'response', 'declined', [
        'message' => 'Card 4242 4242 4242 4242 was declined',
        'order' => '1234567890123',
        'blob' => str_repeat('y', 10_000),
    ]);

    expect($entry->payload)->toHaveKey('truncated', true);

    $small = app(GatewayLogger::class)->log('sandbox_card', 'response', 'declined', ['message' => 'Card 4242 4242 4242 4242 was declined']);
    expect($small->payload['message'])->toBe('Card [redacted-pan] was declined');
});

test('the anonymous return route looks an attempt up at most once a minute', function () {
    sandboxGateway();
    Gateway::query()->where('key', 'sandbox_card')->firstOrFail()->update(['config' => ['webhook_secret' => 'whsec_test', 'checkout_mode' => 'hosted']]);
    [, $client] = actingAsClient();
    $this->post(route('client.invoices.pay', issueInvoice($client, [5_000])->id), ['gateway' => 'sandbox_card'])->assertSessionHasNoErrors();
    $attempt = PaymentAttempt::query()->firstOrFail();

    foreach (range(1, 5) as $hit) {
        $this->get(route('billing.gateways.return', ['sandbox_card', 'ref' => $attempt->reference]));
    }

    expect($attempt->fresh()->lookup_count)->toBe(1);

    $this->travel(61)->seconds();
    $this->get(route('billing.gateways.return', ['sandbox_card', 'ref' => $attempt->reference]));
    expect($attempt->fresh()->lookup_count)->toBe(2);
});
