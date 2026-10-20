<?php

use App\Enums\BillingCycle;
use App\Models\User;
use App\Modules\Billing\Actions\RefundTransaction;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Enums\RefundMode;
use App\Modules\Billing\Enums\TransactionType;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\Transaction;
use App\Modules\Catalog\Models\Product;
use App\Modules\Services\Enums\ServiceStatus;
use App\Modules\Services\Models\ModuleQueueItem;
use App\Modules\Services\Models\Server;
use App\Modules\Services\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\URL;
use Mohar\Simulators\Kernel\Fault;
use Mohar\Simulators\Providers\Stripe\StripeSimulator;
use Mohar\Simulators\Providers\Whm\WhmSimulator;
use Tests\Simulators\SimulatorHarness;
use Tests\TestCase;

require_once dirname(__DIR__).'/Feature/PaymentsStripe/helpers.php';

uses(TestCase::class, SimulatorHarness::class);

/**
 * The hosting lifecycle end to end (ADR 0016 §4), on a fresh database (`php artisan simulate:e2e`): install, a cPanel
 * server trusted the normal way, a product on a WHM package, Stripe against its simulator, a customer who registers,
 * orders and pays on the hosted page, provisioning, overdue suspension, payment and unsuspension, cancellation, a
 * refund and a dispute. Every module is real; only the providers are simulators.
 */
beforeEach(function () {
    $this->startSimulators();
});

afterEach(function () {
    $this->stopSimulators();
});

function step(string $what): void
{
    fwrite(STDERR, "  › {$what}\n");
}

/**
 * Install as an operator would, configure the WHM server (trusted through Test connection) and a product on a WHM
 * package, and turn on Stripe against its simulator. Returns the product.
 */
function installAndConfigure(): Product
{
    $test = test();
    step('app:install without demo data');
    $test->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    expect(Artisan::call('app:install', ['--no-interaction' => true, '--admin-email' => 'owner@example.test', '--admin-name' => 'Olga Owner']))->toBe(0);
    $admin = User::query()->where('email', 'owner@example.test')->sole();
    $admin->forceFill(['password' => 'A-new-owner-password-1'])->save(); // the first sign-in's required change

    step('a cPanel server against the whm simulator, trusted through Test connection');
    $test->actingAs($admin);
    $test->withSession(['auth.password_confirmed_at' => time()]);
    $server = Server::factory()->create(['driver' => 'cpanel', 'name' => 'Simulated WHM', 'hostname' => '127.0.0.1', 'port' => $test->whm->apiPort(), 'username' => 'root', 'api_token' => WhmSimulator::ROOT_TOKEN]);
    $test->post(route('admin.services.servers.test', $server));
    $offered = app('session.store')->get('inertia.flash_data.connectionCheck.details.certificate_spki_sha256');
    $test->post(route('admin.services.servers.trust-certificate', $server), ['fingerprint' => $offered])->assertSessionHasNoErrors();
    $test->post(route('admin.services.servers.test', $server));
    expect(app('session.store')->get('inertia.flash_data.connectionCheck.ok'))->toBeTrue();

    step('a product on the WHM package root_basic, and Stripe in test mode against its simulator');
    $product = Product::factory()->withPrice('USD', BillingCycle::Monthly, 2_500)->create(['name' => 'Shared Basic', 'provisioning_driver' => 'cpanel', 'driver_config' => ['package' => 'root_basic']]);
    stripeGateway(['secret_key' => StripeSimulator::SECRET_KEY, 'webhook_secret' => StripeSimulator::WEBHOOK_SECRET]);
    app('auth')->guard('web')->logout();

    return $product;
}

/**
 * A customer registers, verifies the address, orders the product for their domain and is sent to pay. Returns the
 * invoice.
 */
function registerAndOrder(Product $product): Invoice
{
    $test = test();
    step('a customer registers, verifies the email address and orders');
    $test->post(route('register.store'), ['name' => 'Anna Example', 'email' => 'anna@example-shop.test', 'password' => 'password', 'password_confirmation' => 'password'])->assertRedirect();
    $customer = User::query()->where('email', 'anna@example-shop.test')->sole();
    $test->get(URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $customer->id, 'hash' => sha1($customer->email)]));
    $test->post(route('store.cart.products.store', $product), ['cycle' => 'monthly', 'domain_mode' => 'existing', 'domain_name' => 'example-shop.com'])->assertSessionHasNoErrors();
    $test->post(route('store.checkout.store'), ['payment_gateway' => 'stripe', 'accept_tos' => true, 'idempotency_key' => 'e2e-'.uniqid()])->assertSessionHasNoErrors();

    return Invoice::query()->latest('id')->firstOrFail();
}

/**
 * The customer pays the invoice on the simulator's hosted page; the webhooks settle it. Returns the browser's return URL.
 */
function payWithStripe(Invoice $invoice): string
{
    $test = test();
    $checkout = $test->checkoutUrl($test->post(route('client.invoices.pay', $invoice->id), ['gateway' => 'stripe']));
    $return = $test->payOnHostedPage($checkout);
    expect($test->deliverStripeWebhooks())->toContain('payment recorded');

    return $return;
}

test('a customer orders, pays, is provisioned, suspended when overdue, unsuspended on payment, then cancels; refund and dispute', function () {
    $product = installAndConfigure();
    $invoice = registerAndOrder($product);

    step('Stripe Checkout: hosted page, then webhooks (and a forged one) settle the invoice');
    $checkout = $this->checkoutUrl($this->post(route('client.invoices.pay', $invoice->id), ['gateway' => 'stripe']));
    $return = $this->payOnHostedPage($checkout);
    expect($this->deliverStripeWebhooks('wrong_secret'))->each->toStartWith('refused')
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Unpaid)
        ->and($this->deliverStripeWebhooks(replay: true))->toContain('payment recorded')
        ->and($this->deliverStripeWebhooks(replay: true))->each->toBe('duplicate');
    $this->followIntoApp($return)->assertRedirect();

    step('the invoice is paid, the service is active and the account exists in WHM');
    $service = Service::query()->sole();
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and(Transaction::query()->where('type', TransactionType::Payment)->count())->toBe(1)
        ->and($service->status)->toBe(ServiceStatus::Active)
        ->and($this->whmAccount((string) $service->username))->toMatchArray(['domain' => 'example-shop.com', 'plan' => 'root_basic', 'owner' => 'root', 'suspended' => 0])
        ->and($this->whm->log()->count('createacct'))->toBe(1);

    step('overdue (dates backdated in this throwaway database), then suspended in WHM');
    $due = CarbonImmutable::today()->subDays(8);
    $service->update(['next_due_date' => $due, 'next_invoice_date' => $due]);
    $this->artisan('automation:run', ['--date' => CarbonImmutable::today()->toDateString(), '--only' => 'services.renewals', '--force' => true])->assertSuccessful();
    $this->artisan('automation:run', ['--date' => CarbonImmutable::today()->toDateString(), '--only' => 'services.suspensions', '--force' => true])->assertSuccessful();
    expect($service->refresh()->status)->toBe(ServiceStatus::Suspended)
        ->and($this->whmAccount((string) $service->username)['suspended'])->toBe(1);

    step('the customer pays the renewal; the service and the WHM account are unsuspended');
    $renewal = Invoice::query()->where('id', '!=', $invoice->id)->latest('id')->firstOrFail();
    payWithStripe($renewal);
    expect($renewal->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and($service->refresh()->status)->toBe(ServiceStatus::Active)
        ->and($this->whmAccount((string) $service->username)['suspended'])->toBe(0);

    step('the customer cancels; the account is removed from WHM');
    $this->post(route('client.services.cancel', $service->id), ['type' => 'immediate', 'reason' => 'Moving the site elsewhere.'])->assertSessionHasNoErrors();
    $this->artisan('automation:run', ['--date' => CarbonImmutable::today()->toDateString(), '--only' => 'services.cancellations', '--force' => true])->assertSuccessful();
    expect($service->refresh()->status)->toBe(ServiceStatus::Cancelled)
        ->and($this->whmAccount((string) $service->username))->toBeNull();

    step('a staff refund at Stripe, then a dispute on the renewal payment');
    $first = Transaction::query()->where('type', TransactionType::Payment)->where('invoice_id', $invoice->id)->sole();
    app(RefundTransaction::class)->handle($first, 1_000, RefundMode::Gateway);
    expect($this->deliverStripeWebhooks())->toContain('duplicate')
        ->and(Transaction::query()->where('type', TransactionType::Refund)->count())->toBe(1)
        ->and($this->stripe->log()->count('create_refund'))->toBe(1);

    $second = Transaction::query()->where('type', TransactionType::Payment)->where('invoice_id', $renewal->id)->sole();
    $this->stripe->simulator()->actions()['dispute'](['payment_intent' => $second->gateway_txn_id], $this->stripe);
    expect($this->deliverStripeWebhooks())->toBe(['chargeback recorded'])
        ->and($renewal->fresh()->status)->toBe(InvoiceStatus::Collections);
});

test('a create that commits and then times out is adopted by the verify sweep, with proof, and sent once', function () {
    // createacct gets about 11 seconds, then the client gives up while WHM has already made the account.
    config(['services.module.job_timeout_seconds' => 17]);
    $product = installAndConfigure();
    $invoice = registerAndOrder($product);
    $this->whm->faults()->add('createacct', Fault::commitThenHang(15), nth: 1);

    step('the payment settles; the create times out after WHM committed it');
    payWithStripe($invoice);
    $service = Service::query()->sole();
    expect($service->status)->toBe(ServiceStatus::Pending)
        ->and(ModuleQueueItem::query()->where('subject_id', $service->id)->where('kind', ModuleQueueItem::KIND_VERIFY)->exists())->toBeTrue()
        ->and($this->whmAccount((string) $service->username))->not->toBeNull();

    step('the verify sweep finds our account (domain, owner, time) and completes the service');
    $this->artisan('services:verify-unknown')->assertSuccessful();
    expect($service->refresh()->status)->toBe(ServiceStatus::Active)
        ->and($this->whm->log()->count('createacct'))->toBe(1);
});
