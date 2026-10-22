<?php

use App\Enums\BillingCycle;
use App\Models\User;
use App\Modules\Billing\Enums\AttemptStatus;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Enums\TransactionType;
use App\Modules\Billing\Models\CreditLedgerEntry;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\PaymentAttempt;
use App\Modules\Billing\Models\Transaction;
use App\Modules\Catalog\Models\Product;
use App\Modules\Notifications\Models\EmailMessage;
use App\Modules\PaymentsNepal\Drivers\EsewaDriver;
use App\Modules\PaymentsNepal\Drivers\KhaltiDriver;
use App\Modules\Services\Enums\ServiceStatus;
use App\Modules\Services\Models\ModuleQueueItem;
use App\Modules\Services\Models\Server;
use App\Modules\Services\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\URL;
use Mohar\Simulators\Kernel\Fault;
use Mohar\Simulators\Providers\Esewa\EsewaApi;
use Mohar\Simulators\Providers\Esewa\EsewaSimulator;
use Mohar\Simulators\Providers\Khalti\KhaltiSimulator;
use Mohar\Simulators\Providers\Stripe\StripeSimulator;
use Mohar\Simulators\Providers\Whm\WhmSimulator;
use Tests\Simulators\SimulatorHarness;
use Tests\TestCase;

require_once dirname(__DIR__).'/Feature/PaymentsStripe/helpers.php';
require_once dirname(__DIR__).'/Feature/PaymentsNepal/helpers.php';

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

const E2E_OWNER = 'owner@example.test';

const E2E_OWNER_PASSWORD = 'A-new-owner-password-1';

const E2E_CUSTOMER = 'anna@example-shop.test';

/**
 * The templates of every email sent to $recipient so far.
 *
 * @return list<string>
 */
function mailsTo(string $recipient): array
{
    return EmailMessage::query()->where('recipient', $recipient)->pluck('template_key')->all();
}

/**
 * The administrator signs in again and confirms the password, as the staff routes behind `password.recent` require.
 */
function asOwner(): void
{
    test()->actingAs(User::query()->where('email', E2E_OWNER)->sole());
    test()->post(route('password.confirm.store'), ['password' => E2E_OWNER_PASSWORD])->assertSessionHasNoErrors();
}

function step(string $what): void
{
    fwrite(STDERR, "  › {$what}\n");
}

/**
 * Install as an operator would and replace the one-time password through the password route; then configure the
 * WHM server (trusted through Test connection and "Trust this certificate" on the staff routes), a product on a WHM
 * package, and Stripe against its simulator. Returns the product.
 *
 * Fixtures, not staff routes: the server row, the product and the gateway row are created directly (factories and
 * stripeGateway() or nepalGateway()); the trust flow, the password change, the step-up and the refund go through the
 * routes. $gateway is the payment method: stripe, khalti or esewa, each against its simulator.
 */
function installAndConfigure(string $gateway = 'stripe'): Product
{
    $test = test();
    step('app:install without demo data, keeping the install default currency (NPR)');
    $test->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    expect(Artisan::call('app:install', ['--no-interaction' => true, '--admin-email' => E2E_OWNER, '--admin-name' => 'Olga Owner']))->toBe(0);
    preg_match('/one-time password\..*\R+  (\S{24})\R/', Artisan::output(), $oneTime);
    $admin = User::query()->where('email', E2E_OWNER)->sole();

    step('the administrator replaces the one-time password and confirms it for the sensitive staff routes');
    $test->actingAs($admin);
    $test->put(route('user-password.update'), ['current_password' => $oneTime[1] ?? '', 'password' => E2E_OWNER_PASSWORD, 'password_confirmation' => E2E_OWNER_PASSWORD])->assertSessionHasNoErrors();
    expect($admin->refresh()->must_change_password)->toBeFalse();
    $test->post(route('password.confirm.store'), ['password' => E2E_OWNER_PASSWORD])->assertSessionHasNoErrors();

    step('a cPanel server against the whm simulator, trusted through Test connection');
    $server = Server::factory()->create(['driver' => 'cpanel', 'name' => 'Simulated WHM', 'hostname' => '127.0.0.1', 'port' => $test->whm->apiPort(), 'username' => 'root', 'api_token' => WhmSimulator::ROOT_TOKEN]);
    $test->post(route('admin.services.servers.test', $server));
    $offered = app('session.store')->get('inertia.flash_data.connectionCheck.details.certificate_spki_sha256');
    $test->post(route('admin.services.servers.trust-certificate', $server), ['fingerprint' => $offered])->assertSessionHasNoErrors();
    $test->post(route('admin.services.servers.test', $server));
    expect(app('session.store')->get('inertia.flash_data.connectionCheck.ok'))->toBeTrue();

    step("an NPR product (1,500.50 a month) on the WHM package root_basic, and {$gateway} in test mode against its simulator");
    $product = Product::factory()->withPrice('NPR', BillingCycle::Monthly, 150_050)->create(['name' => 'Shared Basic', 'provisioning_driver' => 'cpanel', 'driver_config' => ['package' => 'root_basic']]);
    match ($gateway) {
        'stripe' => stripeGateway(['secret_key' => StripeSimulator::SECRET_KEY, 'webhook_secret' => StripeSimulator::WEBHOOK_SECRET]),
        KhaltiDriver::KEY => nepalGateway(KhaltiDriver::KEY, ['sandbox' => '1', 'secret_key' => KhaltiSimulator::SECRET_KEY]),
        EsewaDriver::KEY => nepalGateway(EsewaDriver::KEY, ['sandbox' => '1', 'product_code' => EsewaSimulator::PRODUCT_CODE, 'secret_key' => EsewaSimulator::SECRET_KEY]),
    };
    app('auth')->guard('web')->logout();

    return $product;
}

/**
 * A customer registers, verifies the address, orders the product for their domain and is sent to pay. Returns the
 * invoice.
 */
function registerAndOrder(Product $product, string $gateway = 'stripe'): Invoice
{
    $test = test();
    step('a customer registers, verifies the email address and orders');
    $test->post(route('register.store'), ['name' => 'Anna Example', 'email' => E2E_CUSTOMER, 'password' => 'password', 'password_confirmation' => 'password'])->assertRedirect();
    $customer = User::query()->where('email', E2E_CUSTOMER)->sole();
    $test->get(URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $customer->id, 'hash' => sha1($customer->email)]))->assertRedirect();
    expect($customer->refresh()->hasVerifiedEmail())->toBeTrue();
    $test->post(route('store.cart.products.store', $product), ['cycle' => 'monthly', 'domain_mode' => 'existing', 'domain_name' => 'example-shop.com'])->assertSessionHasNoErrors();
    $test->post(route('store.checkout.store'), ['payment_gateway' => $gateway, 'accept_tos' => true, 'idempotency_key' => 'e2e-'.uniqid()])->assertSessionHasNoErrors();

    $invoice = Invoice::query()->latest('id')->firstOrFail();
    expect($invoice->currency)->toBe('NPR');

    return $invoice;
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
    expect($this->deliverStripeWebhooks('wrong_secret'))->not->toBeEmpty()->each->toStartWith('refused')
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Unpaid)
        ->and($this->deliverStripeWebhooks(replay: true))->toContain('payment recorded')
        ->and($this->deliverStripeWebhooks(replay: true))->each->toBe('duplicate');
    $this->get($return)->assertRedirect();

    step('the invoice is paid, the service is active and the account exists in WHM');
    $service = Service::query()->sole();
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and(Transaction::query()->where('type', TransactionType::Payment)->count())->toBe(1)
        ->and($service->status)->toBe(ServiceStatus::Active)
        ->and($this->whmAccount((string) $service->username))->toMatchArray(['domain' => 'example-shop.com', 'plan' => 'root_basic', 'owner' => 'root', 'suspended' => 0])
        ->and($this->whm->log()->count('createacct'))->toBe(1)
        ->and(mailsTo(E2E_CUSTOMER))->toContain('auth.email_verification', 'client.welcome', 'order.confirmation', 'invoice.created', 'invoice.payment_confirmation', 'service.welcome.shared_hosting');

    step('overdue (dates backdated in this throwaway database), then suspended in WHM');
    $due = CarbonImmutable::today()->subDays(8);
    $service->update(['next_due_date' => $due, 'next_invoice_date' => $due]);
    $this->artisan('automation:run', ['--date' => CarbonImmutable::today()->toDateString(), '--only' => 'services.renewals', '--force' => true])->assertSuccessful();
    $this->artisan('automation:run', ['--date' => CarbonImmutable::today()->toDateString(), '--only' => 'services.suspensions', '--force' => true])->assertSuccessful();
    expect($service->refresh()->status)->toBe(ServiceStatus::Suspended)
        ->and($this->whmAccount((string) $service->username)['suspended'])->toBe(1)
        ->and(mailsTo(E2E_CUSTOMER))->toContain('service.suspended');

    step('the customer pays the renewal; the service and the WHM account are unsuspended');
    $renewal = Invoice::query()->where('id', '!=', $invoice->id)->latest('id')->firstOrFail();
    payWithStripe($renewal);
    expect($renewal->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and($service->refresh()->status)->toBe(ServiceStatus::Active)
        ->and($this->whmAccount((string) $service->username)['suspended'])->toBe(0)
        ->and(mailsTo(E2E_CUSTOMER))->toContain('service.unsuspended');

    step('the customer cancels; the account is removed from WHM');
    $this->post(route('client.services.cancel', $service->id), ['type' => 'immediate', 'reason' => 'Moving the site elsewhere.'])->assertSessionHasNoErrors();
    $this->artisan('automation:run', ['--date' => CarbonImmutable::today()->toDateString(), '--only' => 'services.cancellations', '--force' => true])->assertSuccessful();
    expect($service->refresh()->status)->toBe(ServiceStatus::Cancelled)
        ->and($this->whmAccount((string) $service->username))->toBeNull()
        // The only cancellation mail the product sends today (no template confirms the removal itself; raised in #115).
        ->and(mailsTo(E2E_CUSTOMER))->toContain('service.cancellation_requested');

    step('staff refund 10.00 at Stripe from the admin, then the cardholder disputes the renewal payment');
    $first = Transaction::query()->where('type', TransactionType::Payment)->where('invoice_id', $invoice->id)->sole();
    asOwner();
    $this->post(route('admin.billing.transactions.refund', $first), ['amount' => '10.00', 'mode' => 'gateway', 'reverse_effects' => false, 'description' => 'Goodwill refund'])->assertSessionHasNoErrors();
    expect($this->deliverStripeWebhooks())->toContain('duplicate')
        ->and(Transaction::query()->where('type', TransactionType::Refund)->count())->toBe(1)
        ->and($this->stripe->log()->count('create_refund'))->toBe(1);

    $second = Transaction::query()->where('type', TransactionType::Payment)->where('invoice_id', $renewal->id)->sole();
    $this->stripe->simulator()->actions()['dispute'](['payment_intent' => $second->gateway_txn_id], $this->stripe);
    // charge.dispute.created books it; charge.dispute.funds_withdrawn, sent next, is a duplicate.
    expect($this->deliverStripeWebhooks())->toBe(['chargeback recorded', 'duplicate'])
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

test('in NPR through a Nepal wallet: order, pay, provision, suspend when overdue, pay and unsuspend, cancel', function (string $gateway) {
    $product = installAndConfigure($gateway);
    $invoice = registerAndOrder($product, $gateway);

    if ($gateway === EsewaDriver::KEY) {
        step('a forged eSewa return claiming COMPLETE is not trusted; the status check finds nothing paid');
        $form = $this->get((string) $this->post(route('client.invoices.pay', $invoice->id), ['gateway' => $gateway])->headers->get('Location'))->inertiaProps('form');
        expect($this->browser($this->esewa)->asForm()->post($form['action'], (array) $form['fields'])->status())->toBe(302);
        $reference = PaymentAttempt::query()->where('invoice_id', $invoice->id)->sole()->reference;
        $forged = ['transaction_code' => '000FAKE', 'status' => 'COMPLETE', 'total_amount' => '1,500.5', 'transaction_uuid' => $reference, 'product_code' => EsewaSimulator::PRODUCT_CODE, 'signed_field_names' => EsewaApi::RETURN_SIGNED_FIELDS];
        $forged['signature'] = EsewaApi::signature($forged, EsewaApi::RETURN_SIGNED_FIELDS, 'guessed-secret');
        $this->get(route('billing.gateways.return', [$gateway, 'data' => base64_encode((string) json_encode($forged))]));
        expect($invoice->fresh()->status)->toBe(InvoiceStatus::Unpaid);
        // That attempt is still open at eSewa; the customer starts again once it has expired there.
        $this->esewa->simulator()->actions()['settle'](['transaction_uuid' => $reference, 'status' => 'EXPIRED'], $this->esewa);
        $this->travel(8)->minutes();
        $this->artisan('billing:reconcile-payments', ['--only' => 'open'])->assertSuccessful();
        expect(PaymentAttempt::query()->where('reference', $reference)->sole()->status)->toBe(AttemptStatus::Expired);
    }

    step("the customer pays on the {$gateway} page; the return and the lookup settle it, and a replayed return books nothing");
    $return = $this->payWithWallet($invoice, $gateway);
    $this->get($return);
    $service = Service::query()->sole();
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and(Transaction::query()->where('type', TransactionType::Payment)->sole())->amount_in->toBe($invoice->fresh()->total)->gateway->toBe($gateway)
        ->and(CreditLedgerEntry::query()->count())->toBe(0)
        ->and($service->status)->toBe(ServiceStatus::Active)
        ->and($this->whmAccount((string) $service->username))->toMatchArray(['domain' => 'example-shop.com', 'plan' => 'root_basic', 'suspended' => 0]);

    step('overdue, then suspended in WHM; the renewal paid through the wallet unsuspends it');
    $due = CarbonImmutable::today()->subDays(8);
    $service->update(['next_due_date' => $due, 'next_invoice_date' => $due]);
    $this->artisan('automation:run', ['--date' => CarbonImmutable::today()->toDateString(), '--only' => 'services.renewals', '--force' => true])->assertSuccessful();
    $this->artisan('automation:run', ['--date' => CarbonImmutable::today()->toDateString(), '--only' => 'services.suspensions', '--force' => true])->assertSuccessful();
    expect($service->refresh()->status)->toBe(ServiceStatus::Suspended)->and($this->whmAccount((string) $service->username)['suspended'])->toBe(1);
    $renewal = Invoice::query()->where('id', '!=', $invoice->id)->latest('id')->firstOrFail();
    $this->payWithWallet($renewal, $gateway);
    expect($renewal->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and($service->refresh()->status)->toBe(ServiceStatus::Active)
        ->and($this->whmAccount((string) $service->username)['suspended'])->toBe(0);

    step('the customer cancels; the account is removed from WHM');
    $this->post(route('client.services.cancel', $service->id), ['type' => 'immediate', 'reason' => 'Moving the site elsewhere.'])->assertSessionHasNoErrors();
    $this->artisan('automation:run', ['--date' => CarbonImmutable::today()->toDateString(), '--only' => 'services.cancellations', '--force' => true])->assertSuccessful();
    expect($service->refresh()->status)->toBe(ServiceStatus::Cancelled)->and($this->whmAccount((string) $service->username))->toBeNull();

    if ($gateway === KhaltiDriver::KEY) {
        step('staff refund the renewal in full; the driver sends it to Khalti once');
        $paid = Transaction::query()->where('type', TransactionType::Payment)->where('invoice_id', $renewal->id)->sole();
        asOwner();
        $this->post(route('admin.billing.transactions.refund', $paid), ['amount' => number_format($paid->amount_in / 100, 2, '.', ''), 'mode' => 'gateway', 'reverse_effects' => false, 'description' => 'Refund on cancellation'])->assertSessionHasNoErrors();
        expect(Transaction::query()->where('type', TransactionType::Refund)->count())->toBe(1)
            ->and($this->khalti->log()->count('refund'))->toBe(1)
            ->and($this->khalti->state()->get('payments', PaymentAttempt::query()->where('invoice_id', $renewal->id)->sole()->provider_ref)['status'])->toBe('Refunded');
    }
})->with([EsewaDriver::KEY, KhaltiDriver::KEY]);
