<?php

use App\Contracts\Billing\GatewayRegistry;
use App\Modules\Billing\Actions\ConfigureGateway;
use App\Modules\Billing\BillingServiceProvider;
use App\Modules\Billing\Database\Seeders\BillingSeeder;
use App\Modules\Billing\Drivers\Gateways\GatewayManager;
use App\Modules\Billing\Drivers\Gateways\SandboxCardDriver;
use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Billing\Models\Gateway;
use App\Modules\Billing\Models\GatewayLog;
use App\Modules\Billing\Models\PaymentAttempt;
use App\Modules\Billing\Services\GatewayLogger;
use App\Support\Integrations\CertificateBundle;
use App\Support\Permissions\PermissionRegistry;

require_once __DIR__.'/helpers.php';

test('the sandbox gateway is disabled in production unless explicitly forced', function () {
    app()->detectEnvironment(fn () => 'production');

    expect(SandboxCardDriver::enabled())->toBeFalse();

    config(['billing_sandbox.enabled' => true]);
    expect(SandboxCardDriver::enabled())->toBeTrue();

    app()->detectEnvironment(fn () => 'testing');
    config(['billing_sandbox.enabled' => null]);
    expect(SandboxCardDriver::enabled())->toBeTrue();
});

test('a disabled sandbox is not registered and cannot be activated', function () {
    config(['billing_sandbox.enabled' => false]);
    app()->forgetInstance(GatewayManager::class);
    app()->forgetInstance(GatewayRegistry::class);

    expect(app(GatewayRegistry::class)->has(SandboxCardDriver::KEY))->toBeFalse()
        ->and(fn () => app(ConfigureGateway::class)->handle(SandboxCardDriver::KEY, [
            'display_name' => 'Card', 'is_active' => true, 'show_on_order_form' => true, 'sort_order' => 0, 'convert_to_currency' => null,
        ], []))->toThrow(BillingException::class, 'Sandbox gateways can\'t be activated in production.');
});

test('sandbox mode can\'t be turned on in production for any gateway that has one', function (string $key, array $credentials) {
    $withSandboxSetting = collect(app(GatewayRegistry::class)->drivers())
        ->filter(fn ($driver) => collect($driver->settingsFields())->contains('key', 'sandbox'))->keys()->sort()->values()->all();
    // connectIPS also needs its signing certificate before the sandbox check is reached.
    $certificates = [];

    if ($key === 'connectips') {
        $pkey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkcs12_export(openssl_csr_sign(openssl_csr_new(['commonName' => 'CREDITOR'], $pkey), null, $pkey, 30), $pfx, $pkey, 'pass');
        $certificates = ['signing_key' => CertificateBundle::fromPkcs12($pfx, 'pass')];
    }

    app()->detectEnvironment(fn () => 'production');

    expect($withSandboxSetting)->toBe(['connectips', 'cybersource', 'esewa', 'khalti', 'razorpay', 'stripe'])
        ->and(fn () => app(ConfigureGateway::class)->handle($key, ['display_name' => ucfirst($key), 'is_active' => true, 'show_on_order_form' => true, 'sort_order' => 0, 'convert_to_currency' => null], [...$credentials, 'sandbox' => '1'], certificates: $certificates))
        ->toThrow(BillingException::class, 'Sandbox mode can\'t be used in production.')
        ->and(Gateway::query()->where('key', $key)->exists())->toBeFalse();
})->with([
    'stripe' => ['stripe', ['secret_key' => 'sk_test_51SandboxPolicyCheck00000', 'webhook_secret' => 'whsec_SandboxPolicyCheck000000']],
    'khalti' => ['khalti', ['secret_key' => 'test_secret_key_sandbox_policy_check']],
    'esewa' => ['esewa', ['product_code' => 'EPAYTEST', 'secret_key' => 'sandbox-policy-esewa-secret']],
    'cybersource' => ['cybersource', ['merchant_id' => 'm', 'profile_id' => 'p', 'access_key' => 'a', 'secret_key' => 'sandbox-policy-cybersource-secret', 'rest_key_id' => 'k', 'rest_secret' => 'U2FuZGJveFBvbGljeUN5YmVyc291cmNl']],
    'razorpay' => ['razorpay', ['key_id' => 'rzp_test_SandboxPolicy01', 'key_secret' => 'sandbox-policy-razorpay-secret', 'webhook_secret' => 'sandbox-policy-razorpay-webhook']],
    'connectips' => ['connectips', ['merchant_id' => '550', 'app_id' => 'MER-550-APP-1', 'app_name' => 'Hosting', 'app_password' => 'sandbox-policy-connectips-password']],
]);

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
    billingStaff();
    $payment = payInvoice(issueInvoice(billingClient(), [1_000]), 1_000);

    $this->post(route('admin.billing.transactions.refund', $payment), ['amount' => '10.00', 'mode' => 'record_only'])
        ->assertSessionHasErrors('password_confirmation');
    expect($payment->fresh()->refundable_remaining)->toBe(1_000);

    $this->put(route('admin.billing.gateways.update', 'bank_transfer'), ['display_name' => 'X'])->assertSessionHasErrors('password_confirmation');
    $this->post(route('admin.billing.tax-rules.store'), ['name' => 'VAT', 'level' => 1, 'rate' => '5'])->assertSessionHasErrors('password_confirmation');
    $this->postJson(route('admin.billing.invoices.mark-paid', $payment->invoice_id))->assertStatus(423);

    $this->withSession(['auth.step_up_confirmed_at' => now()->getTimestamp()])
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

test('money-moving billing permissions are sensitive: wildcards never grant them', function () {
    $staff = actingAsStaff(['billing.*']);

    foreach (BillingServiceProvider::SENSITIVE_PERMISSIONS as $permission) {
        expect(app(PermissionRegistry::class)->isSensitive($permission))->toBeTrue()
            ->and($staff->hasPermission($permission))->toBeFalse();
    }

    expect($staff->hasPermission('billing.invoices.manage'))->toBeTrue();
});
