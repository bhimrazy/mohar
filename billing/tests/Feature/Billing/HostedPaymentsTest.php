<?php

use App\Contracts\Billing\GatewayRegistry;
use App\Contracts\Billing\HostedPaymentDriver;
use App\Data\Billing\AmountLimits;
use App\Data\Billing\FormPost;
use App\Data\Billing\GatewayConfig;
use App\Data\Billing\LookupResult;
use App\Data\Billing\PaymentAttemptRef;
use App\Data\Billing\PaymentIntent;
use App\Data\Billing\QrPayload;
use App\Data\Billing\RedirectInstruction;
use App\Data\Billing\ReturnHint;
use App\Events\Billing\InvoicePaid;
use App\Modules\Billing\Drivers\Gateways\GatewayManager;
use App\Modules\Billing\Drivers\Gateways\SandboxCardDriver;
use App\Modules\Billing\Enums\AttemptStatus;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Models\Gateway;
use App\Modules\Billing\Models\PaymentAttempt;
use App\Modules\Billing\Models\Transaction;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Support\Billing\AbstractGatewayDriver;
use App\Support\Dashboard\Dashboard;
use App\Support\Money;
use Carbon\CarbonInterval;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/helpers.php';

/**
 * Stand-in for an extension-module driver (e.g. eSewa): NPR only, form-post checkout, lookup-only confirmation.
 */
class FakeNprWalletDriver extends AbstractGatewayDriver implements HostedPaymentDriver
{
    /** @var array<string, LookupResult> provider-side status per reference */
    public static array $provider = [];

    public function key(): string
    {
        return 'fake_wallet';
    }

    public function name(): string
    {
        return 'Fake wallet';
    }

    public function capabilities(): array
    {
        return [self::REDIRECT, self::LOOKUP];
    }

    public function currencies(): array
    {
        return ['NPR'];
    }

    public function settingsFields(): array
    {
        return [['key' => 'secret_key', 'label' => 'Secret key', 'type' => 'secret']];
    }

    public function limits(GatewayConfig $gateway): AmountLimits
    {
        return new AmountLimits(min: 1000, max: 10_000_000);
    }

    public function attemptTtl(GatewayConfig $gateway): CarbonInterval
    {
        return CarbonInterval::minutes(15);
    }

    public function initiate(GatewayConfig $gateway, PaymentIntent $intent): RedirectInstruction|FormPost|QrPayload
    {
        return new FormPost('https://wallet.test/form', ['total_amount' => (string) $intent->amount->amount, 'transaction_uuid' => $intent->reference, 'success_url' => $intent->returnUrl]);
    }

    public function parseReturn(GatewayConfig $gateway, Request $request): ReturnHint
    {
        return new ReturnHint($request->string('ref')->toString() ?: null, claimedStatus: $request->string('status')->toString() ?: null);
    }

    public function lookup(GatewayConfig $gateway, PaymentAttemptRef $attempt): LookupResult
    {
        return self::$provider[$attempt->reference] ?? new LookupResult(LookupResult::NOT_FOUND, raw: ['key' => $gateway->setting('secret_key') ? '[redacted]' : null]);
    }
}

function hostedSandbox(): void
{
    sandboxGateway();
    Gateway::query()->where('key', 'sandbox_card')->firstOrFail()->update(['config' => ['webhook_secret' => 'whsec_test', 'checkout_mode' => 'hosted']]);
}

beforeEach(function () {
    FakeNprWalletDriver::$provider = [];
    app(GatewayRegistry::class)->register(new FakeNprWalletDriver);
    Gateway::query()->create(['key' => 'fake_wallet', 'display_name' => 'Wallet', 'is_active' => true, 'show_on_order_form' => true, 'config' => ['secret_key' => 'super-secret-wallet-key']]);
});

test('the sandbox hosted flow pays only after the lookup, and a replayed return is a no-op', function () {
    Event::fake([InvoicePaid::class]);
    hostedSandbox();
    [, $client] = actingAsClient();
    $invoice = issueInvoice($client, [5_000]);

    $this->post(route('client.invoices.pay', $invoice->id), ['gateway' => 'sandbox_card'], ['X-Inertia' => 'true'])->assertStatus(409);
    $attempt = PaymentAttempt::query()->where('invoice_id', $invoice->id)->firstOrFail();
    expect($attempt)->status->toBe(AttemptStatus::Pending)->amount->toBe(5_000)->reference->toMatch('/^[a-z2-7]{26}$/');

    $this->get(route('billing.sandbox.checkout', $attempt->reference))->assertOk()->assertSee('SANDBOX');
    $returnUrl = $this->post(route('billing.sandbox.checkout.pay', $attempt->reference), ['card_number' => '4242424242424242'])->assertRedirect()->headers->get('Location');

    $this->get($returnUrl)->assertRedirect(route('client.invoices.show', $invoice->id));
    $this->get($returnUrl)->assertRedirect(route('client.invoices.show', $invoice->id));
    $this->post($returnUrl)->assertRedirect();

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and($attempt->fresh()->status)->toBe(AttemptStatus::Succeeded)
        ->and(Transaction::query()->where('gateway', 'sandbox_card')->count())->toBe(1);
    Event::assertDispatchedTimes(InvoicePaid::class, 1);
});

test('a return claiming success is not trusted when the provider says otherwise', function () {
    hostedSandbox();
    [, $client] = actingAsClient();
    $invoice = issueInvoice($client, [5_000]);
    $this->post(route('client.invoices.pay', $invoice->id), ['gateway' => 'sandbox_card']);
    $attempt = PaymentAttempt::query()->firstOrFail();

    $this->get(route('billing.gateways.return', ['sandbox_card', 'ref' => $attempt->reference, 'status' => 'completed']));

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Unpaid)->and($attempt->fresh()->status)->toBe(AttemptStatus::Pending);
});

test('the poller settles an attempt whose customer never returned', function () {
    hostedSandbox();
    [, $client] = actingAsClient();
    $invoice = issueInvoice($client, [5_000]);
    $this->post(route('client.invoices.pay', $invoice->id), ['gateway' => 'sandbox_card']);
    $attempt = PaymentAttempt::query()->firstOrFail();
    SandboxCardDriver::simulate($attempt->reference, 'completed');

    $this->artisan('billing:reconcile-payments')->assertSuccessful();

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)->and($attempt->fresh()->lookup_count)->toBe(1);
});

test('the reconciliation task runs in the daily pipeline', function () {
    hostedSandbox();
    [, $client] = actingAsClient();
    $invoice = issueInvoice($client, [5_000]);
    $this->post(route('client.invoices.pay', $invoice->id), ['gateway' => 'sandbox_card']);
    SandboxCardDriver::simulate(PaymentAttempt::query()->firstOrFail()->reference, 'completed');

    $this->artisan('automation:run', ['--only' => 'billing.payment_reconciliation'])->assertSuccessful();

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid);
});

test('an amount mismatch records nothing and raises a staff todo', function () {
    hostedSandbox();
    [, $client] = actingAsClient();
    $invoice = issueInvoice($client, [5_000]);
    $this->post(route('client.invoices.pay', $invoice->id), ['gateway' => 'sandbox_card']);
    $attempt = PaymentAttempt::query()->firstOrFail();
    SandboxCardDriver::simulate($attempt->reference, 'completed', amount: 100);

    $this->artisan('billing:reconcile-payments');

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Unpaid)
        ->and($attempt->fresh())->status->toBe(AttemptStatus::Ambiguous)->review_reason->toContain('Amount mismatch')
        ->and(Transaction::query()->count())->toBe(0);

    $this->travel(31)->minutes();
    $todo = collect(app(Dashboard::class)->resolveTodos('admin', actingAsStaff(['billing.*'])))->firstWhere('key', 'billing.payment_review');
    expect($todo['count'])->toBe(1);
});

test('unseen attempts expire after the grace period while provider-pending ones go to review', function () {
    [, $client] = actingAsClient(Client::factory()->create(['currency' => 'NPR', 'country' => 'NP']));
    Currency::factory()->npr()->create();
    $unseen = issueInvoice($client, [50_000]);
    $pending = issueInvoice($client, [60_000]);
    $this->post(route('client.invoices.pay', $unseen->id), ['gateway' => 'fake_wallet']);
    $this->post(route('client.invoices.pay', $pending->id), ['gateway' => 'fake_wallet']);
    $pendingAttempt = PaymentAttempt::query()->where('invoice_id', $pending->id)->firstOrFail();
    FakeNprWalletDriver::$provider[$pendingAttempt->reference] = new LookupResult(LookupResult::PENDING);

    $this->travel(16 + 121)->minutes();
    $this->artisan('billing:reconcile-payments');

    expect(PaymentAttempt::query()->where('invoice_id', $unseen->id)->value('status'))->toBe(AttemptStatus::Expired)
        ->and($pendingAttempt->fresh()->status)->toBe(AttemptStatus::Ambiguous);
});

test('NPR-only gateways require NPR or a configured conversion, and use form posts', function () {
    Currency::factory()->npr()->create();
    [, $client] = actingAsClient();
    $usd = issueInvoice($client, [10_000]);

    $this->post(route('client.invoices.pay', $usd->id), ['gateway' => 'fake_wallet'])->assertSessionHasErrors('billing');

    Gateway::query()->where('key', 'fake_wallet')->update(['convert_to_currency' => 'NPR']);
    $this->post(route('client.invoices.pay', $usd->id), ['gateway' => 'fake_wallet'])
        ->assertInertia(fn (Assert $page) => $page->component('client/billing/invoices/checkout')->where('form.action', 'https://wallet.test/form')->where('form.fields.total_amount', '1344000'));

    $attempt = PaymentAttempt::query()->firstOrFail();
    expect($attempt)->amount->toBe(1_344_000)->currency->toBe('NPR')->document_amount->toBe(10_000)->document_currency->toBe('USD');

    FakeNprWalletDriver::$provider[$attempt->reference] = new LookupResult(LookupResult::SUCCEEDED, 'wallet-txn-1', 1_344_000, 'NPR');
    $this->get(route('billing.gateways.return', ['fake_wallet', 'ref' => $attempt->reference]));

    $transaction = Transaction::query()->where('gateway', 'fake_wallet')->firstOrFail();
    expect($usd->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and($transaction)->amount_in->toBe(10_000)->gateway_amount->toBe(1_344_000)->gateway_currency->toBe('NPR');
});

test('clients cannot start payments on other clients invoices and returns are bound to their gateway', function () {
    hostedSandbox();
    $foreign = issueInvoice(Client::factory()->create());
    actingAsClient();

    $this->post(route('client.invoices.pay', $foreign->id), ['gateway' => 'sandbox_card'])->assertNotFound();
    expect(PaymentAttempt::query()->count())->toBe(0);

    $attempt = PaymentAttempt::query()->create(['reference' => 'inv-1-a9', 'gateway' => 'sandbox_card', 'client_id' => $foreign->client_id, 'invoice_id' => $foreign->id,
        'amount' => 100, 'currency' => 'USD', 'document_amount' => 100, 'document_currency' => 'USD', 'expires_at' => now()->addHour()]);
    $this->get(route('billing.gateways.return', ['fake_wallet', 'ref' => $attempt->reference]))->assertRedirect(route('client.invoices.index'));

    expect($attempt->fresh()->lookup_count)->toBe(0);
});

test('every hosted driver honours the contract', function (string $key) {
    hostedSandbox();
    $registry = app(GatewayRegistry::class);
    $driver = $registry->driver($key);
    $config = app(GatewayManager::class)->config($key);
    $ref = new PaymentAttemptRef('inv-0-a1', Money::of(5_000, $driver->currencies()[0]));

    expect($driver)->toBeInstanceOf(HostedPaymentDriver::class)
        ->and($driver->currencies())->not->toBeEmpty()
        ->and($driver->limits($config)->allows(5_000))->toBeTrue();

    $first = $driver->lookup($config, $ref);
    $second = $driver->lookup($config, $ref);

    expect($first->status)->toBeIn(LookupResult::STATUSES)->toBe($second->status);

    foreach ($config->secretValues() as $secret) {
        expect(json_encode($first->raw))->not->toContain($secret);
    }
})->with(['sandbox_card', 'fake_wallet']);
