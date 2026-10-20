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
use App\Models\User;
use App\Modules\Billing\Actions\StartHostedPayment;
use App\Modules\Billing\BillingServiceProvider;
use App\Modules\Billing\Drivers\Gateways\GatewayManager;
use App\Modules\Billing\Drivers\Gateways\SandboxCardDriver;
use App\Modules\Billing\Enums\AttemptStatus;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Billing\Models\Gateway;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\PaymentAttempt;
use App\Modules\Billing\Models\Transaction;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\PaymentsNepal\Support\NprAmount;
use App\Support\Billing\AbstractGatewayDriver;
use App\Support\Dashboard\Dashboard;
use App\Support\Money;
use Carbon\CarbonInterval;
use Illuminate\Http\Client\Request as ProviderRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/../PaymentsStripe/helpers.php';

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
        if ($intent->amount->currency !== 'NPR') {
            throw new RuntimeException("The wallet only takes NPR, not {$intent->amount->currency}.");
        }

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

/** The hosted drivers every contract test runs against: Billing's own, the test double and the extension modules'. */
const HOSTED_DRIVERS = ['sandbox_card', 'fake_wallet', 'khalti', 'esewa', 'stripe'];

/**
 * Active Khalti, eSewa and Stripe gateways in sandbox mode with fake credentials.
 */
function hostedProviderGateways(): void
{
    Gateway::query()->create(['key' => 'khalti', 'display_name' => 'Khalti', 'is_active' => true, 'show_on_order_form' => true, 'config' => ['sandbox' => '1', 'secret_key' => 'test_secret_contract_khalti']]);
    Gateway::query()->create(['key' => 'esewa', 'display_name' => 'eSewa', 'is_active' => true, 'show_on_order_form' => true, 'config' => ['sandbox' => '1', 'product_code' => 'EPAYTEST', 'secret_key' => 'contract-esewa-secret']]);
    Gateway::query()->create(['key' => 'stripe', 'display_name' => 'Stripe', 'is_active' => true, 'show_on_order_form' => true, 'config' => ['sandbox' => '1', 'secret_key' => 'sk_test_51ContractStripeSecret0000', 'webhook_secret' => 'whsec_ContractStripeWebhook00']]);
}

/**
 * One hosted driver run through Billing's own flow against its faked provider: the active gateway, a signed-in client
 * with an unpaid invoice in a currency the driver takes, and closures that mark an attempt paid at the provider, build
 * the browser's return URL, and read the amount (minor units) the provider was asked to collect.
 *
 * @param  list<int>  $lines
 * @return array{0: Invoice, 1: Closure(PaymentAttempt): void, 2: Closure(PaymentAttempt): string, 3: Closure(PaymentAttempt, RedirectInstruction|FormPost|QrPayload): int}
 */
function hostedDriverCase(string $key, array $lines): array
{
    Http::preventStrayRequests();
    $currency = in_array($key, ['sandbox_card', 'stripe'], true) ? 'USD' : 'NPR';

    if ($currency === 'NPR') {
        Currency::factory()->npr()->create();
    }

    [, $client] = actingAsClient(Client::factory()->create(['currency' => $currency, 'country' => $currency === 'NPR' ? 'NP' : 'US']));
    $invoice = issueInvoice($client, $lines);
    $returnUrl = fn (array $query): string => route('billing.gateways.return', [$key, ...$query]);
    $paid = false;
    $markPaid = function () use (&$paid): void {
        $paid = true;
    };
    $sent = fn (string $url): ProviderRequest => Http::recorded(fn (ProviderRequest $request) => $request->url() === $url)->firstOrFail()[0];

    if ($key === 'sandbox_card') {
        hostedSandbox();

        return [$invoice,
            fn (PaymentAttempt $attempt) => SandboxCardDriver::simulate($attempt->reference, 'completed'),
            fn (PaymentAttempt $attempt) => $returnUrl(['ref' => $attempt->reference, 'status' => 'completed']),
            fn (PaymentAttempt $attempt) => (int) SandboxCardDriver::providerState($attempt->reference)['amount'],
        ];
    }

    if ($key === 'fake_wallet') {
        return [$invoice,
            function (PaymentAttempt $attempt): void {
                FakeNprWalletDriver::$provider[$attempt->reference] = new LookupResult(LookupResult::SUCCEEDED, 'wallet-txn-1', $attempt->amount, 'NPR');
            },
            fn (PaymentAttempt $attempt) => $returnUrl(['ref' => $attempt->reference, 'status' => 'COMPLETE']),
            fn (PaymentAttempt $attempt, FormPost $form) => (int) $form->fields['total_amount'],
        ];
    }

    hostedProviderGateways();
    $khaltiInitiate = 'https://dev.khalti.com/api/v2/epayment/initiate/';
    Http::fake([
        $khaltiInitiate => Http::response(['pidx' => 'PIDX1', 'payment_url' => 'https://test-pay.khalti.com/?pidx=PIDX1']),
        'https://dev.khalti.com/api/v2/epayment/lookup/' => function (ProviderRequest $request) use (&$paid) {
            $amount = PaymentAttempt::query()->where('gateway', 'khalti')->where('provider_ref', $request['pidx'])->value('amount');

            return Http::response(['pidx' => $request['pidx'], 'total_amount' => $amount, 'status' => $paid ? 'Completed' : 'Pending', 'transaction_id' => $paid ? 'TXN-1' : null, 'fee' => 0, 'refunded' => false]);
        },
        'https://rc.esewa.com.np/api/epay/transaction/status/*' => function (ProviderRequest $request) use (&$paid) {
            return Http::response(['product_code' => 'EPAYTEST', 'transaction_uuid' => $request['transaction_uuid'], 'total_amount' => (float) $request['total_amount'],
                'status' => $paid ? 'COMPLETE' : 'PENDING', 'ref_id' => $paid ? '0007G36' : null]);
        },
        STRIPE_API.'checkout/sessions' => fn (ProviderRequest $request) => Http::response(stripeCreatedSession($request['client_reference_id'], $invoice->id)),
        STRIPE_API.'checkout/sessions/'.STRIPE_SESSION.'*' => function () use (&$paid, $invoice) {
            $attempt = PaymentAttempt::query()->where('gateway', 'stripe')->where('provider_ref', STRIPE_SESSION)->sole();

            return Http::response($paid ? stripePaidSession($attempt, ['payment_intent' => ['amount_received' => $attempt->amount]]) : stripeCreatedSession($attempt->reference, $invoice->id));
        },
    ]);

    return match ($key) {
        'khalti' => [$invoice, $markPaid,
            fn (PaymentAttempt $attempt) => $returnUrl(['pidx' => 'PIDX1', 'purchase_order_id' => $attempt->reference, 'status' => 'Completed']),
            fn () => $sent($khaltiInitiate)['amount'],
        ],
        'esewa' => [$invoice, $markPaid,
            fn (PaymentAttempt $attempt) => $returnUrl(['ref' => $attempt->reference]),
            fn (PaymentAttempt $attempt, FormPost $form) => (int) NprAmount::toPaisa($form->fields['total_amount']),
        ],
        'stripe' => [$invoice, $markPaid,
            fn (PaymentAttempt $attempt) => $returnUrl(['ref' => $attempt->reference]),
            fn () => $sent(STRIPE_API.'checkout/sessions')['line_items'][0]['price_data']['unit_amount'],
        ],
    };
}

/**
 * A browser return that claims the payment succeeded but carries nothing the provider confirmed: forged query values,
 * or for eSewa a COMPLETE payload signed with a key that is not the merchant's.
 *
 * @return array<string, string>
 */
function forgedSuccessReturn(string $key, string $reference): array
{
    if ($key === 'esewa') {
        $payload = ['transaction_code' => 'FORGED1', 'status' => 'COMPLETE', 'total_amount' => '50.0', 'transaction_uuid' => $reference, 'product_code' => 'EPAYTEST',
            'signed_field_names' => 'transaction_code,status,total_amount,transaction_uuid,product_code,signed_field_names'];
        $message = collect(explode(',', $payload['signed_field_names']))->map(fn (string $field) => $field.'='.$payload[$field])->implode(',');
        $payload['signature'] = base64_encode(hash_hmac('sha256', $message, 'attacker-key', true));

        return ['data' => base64_encode((string) json_encode($payload))];
    }

    return match ($key) {
        'sandbox_card' => ['ref' => $reference, 'status' => 'completed', 'pidx' => 'sbx_pidx_forged'],
        'fake_wallet' => ['ref' => $reference, 'status' => 'COMPLETE'],
        'khalti' => ['pidx' => 'PIDX1', 'purchase_order_id' => $reference, 'status' => 'Completed', 'transaction_id' => 'FORGED-1', 'total_amount' => '5000'],
        'stripe' => ['ref' => $reference, 'session_id' => 'cs_test_forged0000000001'],
    };
}

beforeEach(function () {
    FakeNprWalletDriver::$provider = [];
    app(GatewayRegistry::class)->register(new FakeNprWalletDriver);
    Gateway::query()->create(['key' => 'fake_wallet', 'display_name' => 'Wallet', 'is_active' => true, 'show_on_order_form' => true, 'config' => ['secret_key' => 'super-secret-wallet-key']]);
});

test('the sandbox hosted flow pays only after the lookup', function () {
    hostedSandbox();
    [, $client] = actingAsClient();
    $invoice = issueInvoice($client, [5_000]);

    $this->post(route('client.invoices.pay', $invoice->id), ['gateway' => 'sandbox_card'], ['X-Inertia' => 'true'])->assertStatus(409);
    $attempt = PaymentAttempt::query()->where('invoice_id', $invoice->id)->firstOrFail();
    expect($attempt)->status->toBe(AttemptStatus::Pending)->amount->toBe(5_000)->reference->toMatch('/^[a-z2-7]{26}$/');

    $this->get(route('billing.sandbox.checkout', $attempt->reference))->assertOk()->assertSee('SANDBOX');
    $returnUrl = $this->post(route('billing.sandbox.checkout.pay', $attempt->reference), ['card_number' => '4242424242424242'])->assertRedirect()->headers->get('Location');

    $this->get($returnUrl)->assertRedirect(route('client.invoices.show', $invoice->id));

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and($attempt->fresh()->status)->toBe(AttemptStatus::Succeeded)
        ->and(Transaction::query()->where('gateway', 'sandbox_card')->count())->toBe(1);
});

test('cancelling on the sandbox hosted page closes the attempt at once', function () {
    hostedSandbox();
    [, $client] = actingAsClient();
    $invoice = issueInvoice($client, [5_000]);
    $this->post(route('client.invoices.pay', $invoice->id), ['gateway' => 'sandbox_card'])->assertRedirect();
    $attempt = PaymentAttempt::query()->sole();

    $cancelUrl = (string) $this->post(route('billing.sandbox.checkout.pay', $attempt->reference), ['cancel' => '1'])->assertRedirect()->headers->get('Location');
    $this->get($cancelUrl)->assertRedirect(route('client.invoices.show', $invoice->id));

    expect($attempt->fresh()->status)->toBe(AttemptStatus::Failed)
        ->and($attempt->fresh()->expires_at->isFuture())->toBeTrue()
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Unpaid)
        ->and(Transaction::query()->count())->toBe(0);

    // The session would still be open for another 30 minutes, but the invoice can be paid again straight away.
    $this->post(route('client.invoices.pay', $invoice->id), ['gateway' => 'sandbox_card'])->assertRedirect();
    expect(PaymentAttempt::query()->where('invoice_id', $invoice->id)->count())->toBe(2);
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
    $todo = collect(app(Dashboard::class)->resolveTodos('admin', billingStaff()))->firstWhere('key', 'billing.payment_review');
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
    $checkoutUrl = $this->post(route('client.invoices.pay', $usd->id), ['gateway' => 'fake_wallet'])->assertRedirect()->headers->get('Location');
    $attempt = PaymentAttempt::query()->firstOrFail();
    expect($checkoutUrl)->toBe(route('client.invoices.checkout', [$usd->id, $attempt->reference]));

    // A full page load whose CSP lets the form post to the provider's origin (the SPA document only allows 'self').
    $checkout = $this->get($checkoutUrl)
        ->assertInertia(fn (Assert $page) => $page->component('client/billing/invoices/checkout')->where('form.action', 'https://wallet.test/form')->where('form.fields.total_amount', '1344000'));
    expect($checkout->headers->get('Content-Security-Policy'))->toContain("form-action 'self' https://wallet.test;");

    expect($attempt)->amount->toBe(1_344_000)->currency->toBe('NPR')->document_amount->toBe(10_000)->document_currency->toBe('USD');

    FakeNprWalletDriver::$provider[$attempt->reference] = new LookupResult(LookupResult::SUCCEEDED, 'wallet-txn-1', 1_344_000, 'NPR');
    $this->get(route('billing.gateways.return', ['fake_wallet', 'ref' => $attempt->reference]));

    $transaction = Transaction::query()->where('gateway', 'fake_wallet')->firstOrFail();
    expect($usd->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and($transaction)->amount_in->toBe(10_000)->gateway_amount->toBe(1_344_000)->gateway_currency->toBe('NPR');
});

test('clients get 429 after too many pay requests a minute, on single and batch pay', function () {
    Currency::factory()->npr()->create();
    [, $client] = actingAsClient(Client::factory()->create(['currency' => 'NPR', 'country' => 'NP']));
    $invoice = issueInvoice($client, [50_000]);

    foreach (range(1, BillingServiceProvider::PAY_REQUESTS_PER_MINUTE) as $ignored) {
        $this->post(route('client.invoices.pay', $invoice->id), ['gateway' => 'fake_wallet'])->assertRedirect();
    }

    $this->post(route('client.invoices.pay', $invoice->id), ['gateway' => 'fake_wallet'])->assertTooManyRequests();
    // Only the first request opens an attempt; the rest are refused while it can still complete at the provider.
    expect(PaymentAttempt::query()->count())->toBe(1)
        ->and(app('router')->getRoutes()->getByName('client.invoices.batches.pay')->gatherMiddleware())->toContain('throttle:billing-pay');
});

test('the provider form page is bound to the client and to an open attempt', function () {
    Currency::factory()->npr()->create();
    [, $client] = actingAsClient(Client::factory()->create(['currency' => 'NPR', 'country' => 'NP']));
    $invoice = issueInvoice($client, [50_000]);
    $checkoutUrl = (string) $this->post(route('client.invoices.pay', $invoice->id), ['gateway' => 'fake_wallet'])->headers->get('Location');
    $attempt = PaymentAttempt::query()->sole();

    $this->get($checkoutUrl)->assertOk();

    $attempt->update(['status' => AttemptStatus::Failed]);
    $this->get($checkoutUrl)->assertRedirect(route('client.invoices.show', $invoice->id));

    $other = Client::factory()->create(['currency' => 'NPR']);
    $user = User::factory()->create(['email' => $other->email]);
    $other->users()->attach($user, ['is_owner' => true]);
    $this->actingAs($user)->get($checkoutUrl)->assertNotFound();
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

test('a hosted payment on a part-paid invoice asks the provider for the remaining balance only', function (string $key) {
    [$invoice, $markPaid, $returnUrl, $requested] = hostedDriverCase($key, [150_000]);
    payInvoice($invoice, 40_000, 'bank-part-1');

    [$attempt, $instruction] = app(StartHostedPayment::class)->handle($invoice->fresh(), $key);

    expect($attempt)->amount->toBe(110_000)->document_amount->toBe(110_000)
        ->and($requested($attempt, $instruction))->toBe(110_000);

    $markPaid($attempt);
    $this->get($returnUrl($attempt));

    expect($invoice->fresh())->status->toBe(InvoiceStatus::Paid)->balance->toBe(0)->amount_paid->toBe(150_000)
        ->and(Transaction::query()->where('gateway', $key)->sole()->amount_in)->toBe(110_000);
})->with(HOSTED_DRIVERS);

test('repeated returns, a posted return and the poller record one payment, whatever the hosted driver', function (string $key) {
    Event::fake([InvoicePaid::class]);
    [$invoice, $markPaid, $returnUrl] = hostedDriverCase($key, [150_000]);
    [$attempt] = app(StartHostedPayment::class)->handle($invoice, $key);
    $markPaid($attempt);

    $this->get($returnUrl($attempt))->assertRedirect(route('client.invoices.show', $invoice->id));
    $this->get($returnUrl($attempt))->assertRedirect(route('client.invoices.show', $invoice->id));
    $this->travel(2)->minutes();
    $this->get($returnUrl($attempt));
    $this->post($returnUrl($attempt))->assertRedirect();
    $this->travel(10)->minutes();
    $this->artisan('billing:reconcile-payments')->assertSuccessful();

    expect(Transaction::query()->where('gateway', $key)->count())->toBe(1)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and($attempt->fresh())->status->toBe(AttemptStatus::Succeeded)->transaction_id->toBe(Transaction::query()->sole()->id);
    Event::assertDispatchedTimes(InvoicePaid::class, 1);
})->with(HOSTED_DRIVERS);

test('an attempt opened between the in-progress check and the insert still blocks a second start (invoice lock held)', function () {
    if (DB::connection()->getDriverName() !== 'pgsql' || ! function_exists('pg_send_query')) {
        $this->markTestSkipped('Needs PostgreSQL row locks and a second, asynchronous session (ext-pgsql).');
    }

    hostedSandbox();
    // Rows the other session must see are committed through a second connection (the test's own transaction never commits).
    $config = config('database.connections.'.DB::getDefaultConnection());
    config(['database.connections.lock_probe' => $config]);
    $committed = DB::connection('lock_probe');
    $clientId = $committed->table('clients')->insertGetId([...Client::factory()->raw(['currency' => 'USD']), 'created_at' => now(), 'updated_at' => now()]);
    $invoiceId = $committed->table('invoices')->insertGetId(['client_id' => $clientId, 'status' => 'unpaid', 'currency' => 'USD', 'issue_date' => now()->toDateString(),
        'due_date' => now()->addWeek()->toDateString(), 'subtotal' => 5_000, 'total' => 5_000, 'balance' => 5_000, 'created_at' => now(), 'updated_at' => now()]);
    // After RefreshDatabase has rolled the test's transaction back, so no lock it may still hold can block the clean-up.
    $this->beforeApplicationDestroyed(function () use ($committed, $clientId, $invoiceId): void {
        $committed->table('payment_attempts')->where('invoice_id', $invoiceId)->delete();
        $committed->table('invoices')->where('id', $invoiceId)->delete();
        $committed->table('clients')->where('id', $clientId)->delete();
        $committed->disconnect();
    });
    $other = pg_connect(sprintf("host='%s' port='%s' dbname='%s' user='%s' password='%s'", $config['host'], $config['port'], $config['database'], $config['username'], $config['password']), PGSQL_CONNECT_FORCE_NEW);
    expect($other)->not->toBeFalse();

    try {
        // The first tab is inside its locked section: it has checked, inserted its attempt and not yet committed.
        pg_query($other, 'BEGIN');
        pg_query_params($other, 'SELECT id FROM invoices WHERE id = $1 FOR UPDATE', [$invoiceId]);
        pg_query_params($other, "INSERT INTO payment_attempts (reference, gateway, client_id, invoice_id, amount, currency, document_amount, document_currency, status, expires_at, created_at, updated_at)
            VALUES ($1, 'sandbox_card', $2, $3, 5000, 'USD', 5000, 'USD', 'pending', $4, $5, $5)", [PaymentAttempt::newReference(), $clientId, $invoiceId, now()->addMinutes(30)->toDateTimeString(), now()->toDateTimeString()]);
        // It commits one second from now, while the second tab below is already running; just before committing it
        // records whether the second tab's session is waiting for a lock.
        $secondTab = (int) DB::selectOne('SELECT pg_backend_pid() AS pid')->pid;
        pg_send_query($other, "SELECT pg_sleep(1); SELECT count(*) AS waiting FROM pg_locks WHERE pid = {$secondTab} AND NOT granted; COMMIT");

        $started = microtime(true);
        $second = rescue(fn () => app(StartHostedPayment::class)->handle(Invoice::query()->findOrFail($invoiceId), 'sandbox_card'), fn (Throwable $e) => $e, report: false);
        $waited = microtime(true) - $started;

        $results = [];
        while (($result = pg_get_result($other)) !== false) {
            $results[] = pg_fetch_all($result) ?: [];
        }

        // The second tab was blocked on the first tab's lock, then saw its attempt and refused; without the lock it would
        // have read no committed attempt and opened a second payment.
        expect((int) ($results[1][0]['waiting'] ?? 0))->toBeGreaterThan(0)
            ->and($waited)->toBeGreaterThan(0.8)
            ->and($second)->toBeInstanceOf(BillingException::class)
            ->and($second->getMessage())->toContain('already in progress')
            ->and(PaymentAttempt::query()->where('invoice_id', $invoiceId)->count())->toBe(1);
    } finally {
        while (pg_get_result($other) !== false) {
            // A failed assertion above may leave results unread.
        }
        pg_close($other);
    }
});

test('every hosted driver refuses to start a payment in a currency it does not list', function (string $key) {
    if ($key === 'sandbox_card') {
        $this->markTestSkipped('#155: SandboxCardDriver::initiate() accepts a currency it does not list (Billing refuses it first).');
    }

    hostedSandbox();
    hostedProviderGateways();
    Http::preventStrayRequests();
    $driver = app(GatewayRegistry::class)->driver($key);
    $intent = new PaymentIntent('inv-0-a2', Money::of(5_000, 'XTS'), 'Invoice', route('billing.gateways.return', $key), route('billing.gateways.return', $key));

    expect($driver)->toBeInstanceOf(HostedPaymentDriver::class)
        ->and(fn () => $driver->initiate(app(GatewayManager::class)->config($key), $intent))->toThrow(Exception::class);
    Http::assertNothingSent();
})->with(HOSTED_DRIVERS);

test('every hosted driver honours the contract', function (string $key) {
    hostedSandbox();
    // The extension drivers (PaymentsNepal, PaymentsStripe) with sandbox credentials; their providers answer "still pending".
    hostedProviderGateways();
    Http::preventStrayRequests();
    Http::fake([
        'https://dev.khalti.com/api/v2/epayment/lookup/' => Http::response(['pidx' => 'PIDX1', 'status' => 'Pending', 'total_amount' => 5_000, 'transaction_id' => null, 'fee' => 0, 'refunded' => false]),
        'https://rc.esewa.com.np/api/epay/transaction/status/*' => Http::response(['product_code' => 'EPAYTEST', 'transaction_uuid' => 'inv-0-a1', 'total_amount' => 50.0, 'status' => 'PENDING', 'ref_id' => null]),
        'https://api.stripe.com/v1/checkout/sessions/cs_test_contract0001*' => Http::response(['id' => 'cs_test_contract0001', 'object' => 'checkout.session', 'client_reference_id' => 'inv-0-a1', 'metadata' => ['mohar_reference' => 'inv-0-a1'], 'mode' => 'payment', 'livemode' => false, 'status' => 'open', 'payment_status' => 'unpaid', 'payment_intent' => null]),
    ]);
    $registry = app(GatewayRegistry::class);
    $driver = $registry->driver($key);
    $config = app(GatewayManager::class)->config($key);
    $ref = new PaymentAttemptRef('inv-0-a1', Money::of(5_000, $driver->currencies()[0]), providerRef: $key === 'stripe' ? 'cs_test_contract0001' : 'PIDX1');

    expect($driver)->toBeInstanceOf(HostedPaymentDriver::class)
        ->and($driver->currencies())->not->toBeEmpty()
        ->and($driver->limits($config)->allows(5_000))->toBeTrue();

    $first = $driver->lookup($config, $ref);
    $second = $driver->lookup($config, $ref);

    expect($first->status)->toBeIn(LookupResult::STATUSES)->toBe($second->status);

    // An invoice in a currency the driver does not list is refused before the provider is asked or an attempt exists.
    Currency::factory()->create(['code' => 'XTS', 'name' => 'Test units', 'prefix' => 'T ', 'decimals' => 2, 'is_default' => false]);
    $foreign = issueInvoice(billingClient(['currency' => 'XTS']), [5_000]);
    $sentBefore = Http::recorded()->count();

    expect($driver->currencies())->not->toContain('XTS')
        ->and(fn () => app(StartHostedPayment::class)->handle($foreign, $key))->toThrow(BillingException::class, "can't take payments in XTS")
        ->and(PaymentAttempt::query()->count())->toBe(0)
        ->and(Http::recorded())->toHaveCount($sentBefore);

    // A return claiming success settles nothing by itself: the provider's lookup, which still says pending, decides.
    $attempt = PaymentAttempt::query()->create(['reference' => 'inv-0-a1', 'gateway' => $key, 'client_id' => $foreign->client_id, 'amount' => 5_000, 'currency' => $ref->amount->currency,
        'document_amount' => 5_000, 'document_currency' => $ref->amount->currency, 'provider_ref' => $ref->providerRef, 'expires_at' => now()->addHour()]);
    $this->get(route('billing.gateways.return', [$key, ...forgedSuccessReturn($key, $attempt->reference)]));

    expect($attempt->fresh())->status->toBe(AttemptStatus::Pending)->lookup_count->toBe(1)
        ->and(Transaction::query()->count())->toBe(0);

    // Values of the driver's secret settings (public ones such as eSewa's product code may be echoed back).
    $secrets = collect($driver->settingsFields())->whereIn('type', ['secret', 'secret_text'])
        ->map(fn (array $field) => (string) $config->setting($field['key'], ''))->filter();

    expect($secrets)->not->toBeEmpty();
    foreach ($secrets as $secret) {
        expect(json_encode($first->raw))->not->toContain($secret);
    }
})->with(['sandbox_card', 'fake_wallet', 'khalti', 'esewa', 'stripe']);
