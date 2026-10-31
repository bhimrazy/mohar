<?php

use App\Modules\Billing\Actions\CaptureInvoice;
use App\Modules\Billing\Actions\ManagePayMethods;
use App\Modules\Billing\Actions\StartHostedPayment;
use App\Modules\Billing\Drivers\Gateways\GatewayManager;
use App\Modules\Billing\Drivers\Gateways\SandboxCardDriver;
use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Billing\Models\Gateway;
use App\Modules\Billing\Models\PaymentAttempt;
use App\Modules\Billing\Models\PayMethod;
use App\Modules\Billing\Models\Transaction;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/WebhookHelpers.php';

/**
 * ADR 0030 A1: an inactive gateway that has settled work keeps its webhook and return open, but every new-work entry
 * point still refuses it, and the settlement path stays behind the stored signature secret.
 */
beforeEach(function () {
    Currency::factory()->create(['code' => 'NPR', 'name' => 'Nepalese Rupee', 'prefix' => 'रू ', 'exchange_rate' => 134.4, 'is_default' => false]);
    [, $this->client] = actingAsClient(Client::factory()->create(['currency' => 'NPR']));
    $this->invoice = issueInvoice($this->client, [11_300]);
});

test('a used, inactive gateway is refused by every new-work entry point', function () {
    Gateway::factory()->sandbox()->create(['config' => ['webhook_secret' => 'whsec_test', 'checkout_mode' => 'hosted']]);
    $this->post(route('client.invoices.pay', $this->invoice->id), ['gateway' => 'sandbox_card']);
    $method = PayMethod::factory()->create(['client_id' => $this->client->id]);
    Gateway::query()->where('key', 'sandbox_card')->update(['is_active' => false]);

    expect(app(GatewayManager::class)->settles('sandbox_card'))->toBeTrue()
        ->and(fn () => app(StartHostedPayment::class)->handle($this->invoice->fresh(), 'sandbox_card'))->toThrow(BillingException::class)
        ->and(fn () => app(CaptureInvoice::class)->handle($this->invoice->fresh(), 'sandbox_card', $method->token, $method))->toThrow(BillingException::class)
        ->and(fn () => app(ManagePayMethods::class)->store($this->client->id, 'sandbox_card', $method->token))->toThrow(BillingException::class);

    expect(PaymentAttempt::query()->count())->toBe(1)
        ->and(Transaction::query()->count())->toBe(0);
});

test('an inactive gateway opened only by a recorded transaction, with no stored secret, refuses an unsigned webhook', function () {
    Gateway::factory()->sandbox()->create(['is_active' => false, 'config' => []]);
    payInvoice($this->invoice, 1_000, 'manual-ref-1', 'sandbox_card');

    expect(app(GatewayManager::class)->settles('sandbox_card'))->toBeTrue();

    sandboxWebhook(['type' => 'payment.succeeded', 'invoice_id' => $this->invoice->id, 'txn_id' => 'sbx_forged', 'amount' => 10_300, 'currency' => 'NPR'], '')
        ->assertForbidden();

    expect(Transaction::query()->where('gateway_txn_id', 'sbx_forged')->exists())->toBeFalse();
});

test('a settled return then the webhook for the same inactive-gateway payment books it once', function () {
    Gateway::factory()->sandbox()->create(['config' => ['webhook_secret' => 'whsec_test', 'checkout_mode' => 'hosted']]);
    $this->post(route('client.invoices.pay', $this->invoice->id), ['gateway' => 'sandbox_card']);
    $attempt = PaymentAttempt::query()->sole();
    Gateway::query()->where('key', 'sandbox_card')->update(['is_active' => false]);
    SandboxCardDriver::simulate($attempt->reference, 'completed');

    $this->get(route('billing.gateways.return', ['sandbox_card', 'ref' => $attempt->reference]))->assertRedirect();
    $txn = SandboxCardDriver::providerState($attempt->reference)['txn_id'];
    sandboxWebhook(['type' => 'payment.succeeded', 'invoice_id' => $this->invoice->id, 'txn_id' => $txn, 'amount' => 11_300, 'currency' => 'NPR'])
        ->assertOk()->assertJson(['result' => 'duplicate']);

    expect(Transaction::query()->where('gateway', 'sandbox_card')->count())->toBe(1);
});

test('a key whose driver is not registered is refused even with a saved row and transactions', function () {
    Gateway::query()->create(['key' => 'gone_gateway', 'display_name' => 'Gone', 'is_active' => false, 'config' => ['webhook_secret' => 'x']]);
    payInvoice($this->invoice, 1_000, 'old-ref', 'gone_gateway');

    expect(app(GatewayManager::class)->settles('gone_gateway'))->toBeFalse();
    $this->call('POST', route('billing.webhooks', 'gone_gateway'), [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}')->assertNotFound();
});
