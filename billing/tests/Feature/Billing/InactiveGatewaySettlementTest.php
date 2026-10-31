<?php

use App\Events\Billing\InvoicePaid;
use App\Modules\Billing\Drivers\Gateways\SandboxCardDriver;
use App\Modules\Billing\Enums\AttemptStatus;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Models\Gateway;
use App\Modules\Billing\Models\GatewayLog;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\PaymentAttempt;
use App\Modules\Billing\Models\Transaction;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/WebhookHelpers.php';

/**
 * ADR 0030 A1 (#571): deactivating a gateway stops new payments; webhooks and customer returns still settle the
 * payments it already started.
 */
beforeEach(function () {
    Gateway::factory()->sandbox('whsec_test')->create(['config' => ['webhook_secret' => 'whsec_test', 'checkout_mode' => 'hosted']]);
    Currency::factory()->create(['code' => 'NPR', 'name' => 'Nepalese Rupee', 'prefix' => 'रू ', 'exchange_rate' => 134.4, 'is_default' => false]);
    [, $this->client] = actingAsClient(Client::factory()->create(['currency' => 'NPR']));
    $this->invoice = issueInvoice($this->client, [11_300]);
});

/** A hosted attempt the client started, then staff switched the gateway off. */
function attemptThenDeactivate(Invoice $invoice): PaymentAttempt
{
    test()->post(route('client.invoices.pay', $invoice->id), ['gateway' => 'sandbox_card']);
    $attempt = PaymentAttempt::query()->where('invoice_id', $invoice->id)->sole();
    Gateway::query()->where('key', 'sandbox_card')->update(['is_active' => false]);

    return $attempt;
}

test('a payment in flight when its gateway is deactivated settles once from the webhook, and the return lands on the paid invoice', function () {
    Event::fake([InvoicePaid::class]);
    $attempt = attemptThenDeactivate($this->invoice);
    SandboxCardDriver::simulate($attempt->reference, 'completed');
    $payload = ['type' => 'payment.succeeded', 'invoice_id' => $this->invoice->id, 'txn_id' => SandboxCardDriver::providerState($attempt->reference)['txn_id'], 'amount' => 11_300, 'currency' => 'NPR'];

    sandboxWebhook($payload)->assertOk()->assertJson(['result' => 'payment recorded']);
    sandboxWebhook($payload)->assertOk()->assertJson(['result' => 'duplicate']);
    $this->get(route('billing.gateways.return', ['sandbox_card', 'ref' => $attempt->reference]))
        ->assertRedirect(route('client.invoices.show', $this->invoice->id));

    expect($this->invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and($attempt->fresh()->status)->toBe(AttemptStatus::Succeeded)
        ->and(Transaction::query()->where('gateway', 'sandbox_card')->count())->toBe(1);
    Event::assertDispatchedTimes(InvoicePaid::class, 1);
});

test('a deactivated gateway is not offered at checkout and cannot start a new payment', function () {
    Gateway::query()->where('key', 'sandbox_card')->update(['is_active' => false]);

    $this->get(route('client.invoices.show', $this->invoice->id))
        ->assertInertia(fn (Assert $page) => $page->where('payment.gateways', fn ($gateways) => collect($gateways)->where('key', 'sandbox_card')->isEmpty()));
    $this->post(route('client.invoices.pay', $this->invoice->id), ['gateway' => 'sandbox_card'])->assertSessionHasErrors();

    expect(PaymentAttempt::query()->count())->toBe(0);
});

test('a forged webhook for a deactivated gateway is still rejected by its stored secret', function () {
    attemptThenDeactivate($this->invoice);

    sandboxWebhook(['type' => 'payment.succeeded', 'invoice_id' => $this->invoice->id, 'txn_id' => 'sbx_tx_forged', 'amount' => 11_300, 'currency' => 'NPR'], 'attacker-secret')
        ->assertForbidden();

    expect(Transaction::query()->count())->toBe(0)
        ->and($this->invoice->fresh()->status)->toBe(InvoiceStatus::Unpaid)
        ->and(GatewayLog::query()->where('result', 'invalid signature')->exists())->toBeTrue();
});

test('an inactive gateway that never took a payment refuses webhooks and returns', function () {
    Gateway::query()->where('key', 'sandbox_card')->update(['is_active' => false]);

    sandboxWebhook(['type' => 'payment.succeeded', 'invoice_id' => $this->invoice->id, 'txn_id' => 'sbx_tx_1', 'amount' => 11_300, 'currency' => 'NPR'])
        ->assertNotFound();
    $this->get(route('billing.gateways.return', ['sandbox_card', 'ref' => 'abcdefghijklmnopqrstuvwxyz']))->assertNotFound();

    expect(Transaction::query()->count())->toBe(0);
});
