<?php

use App\Models\User;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Domains\Actions\IssueRenewalNow;
use App\Modules\Domains\Drivers\Registrars\ResellerClub\ResellerClubDriver;
use App\Modules\Domains\Enums\DomainStatus;
use App\Modules\Domains\Models\Domain;
use App\Modules\Domains\Models\RegistrarAccount;
use App\Modules\Domains\Models\Tld;
use App\Modules\PaymentsNepal\Drivers\KhaltiDriver;
use App\Support\Integrations\Endpoints;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\URL;
use Mohar\Simulators\Kernel\Processes;
use Mohar\Simulators\Kernel\Scenario;
use Mohar\Simulators\Providers\Khalti\KhaltiSimulator;
use Mohar\Simulators\Providers\ResellerClub\ResellerClubApi;
use Mohar\Simulators\Providers\ResellerClub\ResellerClubSimulator;
use Tests\Simulators\SimulatorHarness;
use Tests\TestCase;

require_once dirname(__DIR__).'/Feature/PaymentsNepal/helpers.php';

uses(TestCase::class, SimulatorHarness::class);

/**
 * A domain end to end (ADR 0016 §4) on a fresh database: a customer orders asha-shop.com, pays in NPR with Khalti, the
 * paid invoice registers it at ResellerClub (the simulator), then a renewal invoice is paid and the domain is renewed
 * there once. Every module is real; Khalti and ResellerClub are simulators.
 */
beforeEach(function () {
    $this->startSimulators();
    $this->resellerclub = Processes::start(ResellerClubSimulator::class);
    $bundle = (string) config('integrations.endpoints.ca_bundle');
    file_put_contents($bundle, (string) file_get_contents($this->resellerclub->ca()->bundlePath()), FILE_APPEND);
    config(['integrations.endpoints.overrides' => json_encode([
        ...json_decode((string) config('integrations.endpoints.overrides'), true),
        ...json_decode($this->resellerclub->simulator()->appConfiguration($this->resellerclub)['env']['INTEGRATION_ENDPOINTS'], true),
    ], JSON_UNESCAPED_SLASHES)]);
    app()->forgetInstance(Endpoints::class);
});

afterEach(function () {
    if (isset($this->resellerclub)) {
        Processes::stop($this->resellerclub);
    }

    $this->stopSimulators();
});

/** @return array<string, mixed> */
function rcE2eOrder(Scenario $scenario, string $name): array
{
    return (new ResellerClubApi($scenario))->orderByName($name) ?? throw new RuntimeException("No ResellerClub order for {$name}.");
}

test('a customer orders a domain, pays with Khalti, it is registered at ResellerClub, then renewed there once', function () {
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    expect(Artisan::call('app:install', ['--no-interaction' => true, '--admin-email' => 'owner@example.test', '--admin-name' => 'Olga Owner']))->toBe(0);

    // Fixtures: the .com price, the ResellerClub account in test mode, and Khalti in sandbox mode.
    Tld::factory()->extension('.com')->priced('NPR', 150_050, 140_000, 160_075)->create(['registrar_key' => ResellerClubDriver::KEY, 'auto_register' => true]);
    RegistrarAccount::query()->create(['key' => ResellerClubDriver::KEY, 'is_active' => true, 'test_mode' => true, 'settings' => [
        'demo_reseller_id' => ResellerClubSimulator::RESELLER_ID, 'demo_api_key' => ResellerClubSimulator::API_KEY, 'customer_mode' => 'per_client',
    ]]);
    nepalGateway(KhaltiDriver::KEY, ['sandbox' => '1', 'secret_key' => KhaltiSimulator::SECRET_KEY]);

    $this->post(route('register.store'), ['name' => 'Asha Shrestha', 'email' => 'asha@example-shop.test', 'password' => 'password', 'password_confirmation' => 'password'])->assertRedirect();
    $customer = User::query()->where('email', 'asha@example-shop.test')->sole();
    $this->get(URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $customer->id, 'hash' => sha1($customer->email)]))->assertRedirect();
    $customer->clients()->sole()->forceFill(['phone' => '+977.15550100', 'address_1' => '1 Durbar Marg', 'city' => 'Kathmandu', 'state' => 'Bagmati', 'postcode' => '44600', 'country' => 'NP'])->save();

    $this->post(route('store.cart.domains.store'), ['domain' => 'asha-shop.com', 'action' => 'register', 'years' => 1])->assertSessionHasNoErrors();
    $this->post(route('store.checkout.store'), ['payment_gateway' => KhaltiDriver::KEY, 'accept_tos' => true, 'idempotency_key' => 'e2e-'.uniqid()])->assertSessionHasNoErrors();
    $invoice = Invoice::query()->latest('id')->firstOrFail();
    $this->payWithWallet($invoice, KhaltiDriver::KEY);

    $domain = Domain::query()->where('name', 'asha-shop.com')->sole();
    $order = rcE2eOrder($this->resellerclub, 'asha-shop.com');
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and($domain->status)->toBe(DomainStatus::Active)
        ->and($domain->registrar_meta['remote']['order_id'])->toBe((int) $order['orderid'])
        ->and($domain->expiry_date->toDateString())->toBe(CarbonImmutable::createFromTimestamp((int) $order['endtime'])->toDateString())
        ->and($this->resellerclub->log()->count('register'))->toBe(1)
        ->and(head($this->resellerclub->state()->all('customers'))['username'])->toBe('asha@example-shop.test');

    $renewal = app(IssueRenewalNow::class)->handle($domain, 'staff');
    $this->payWithWallet(Invoice::query()->findOrFail($renewal->invoiceId), KhaltiDriver::KEY);
    $renewed = rcE2eOrder($this->resellerclub, 'asha-shop.com');

    expect((int) $renewed['endtime'])->toBe((int) (new DateTimeImmutable('@'.$order['endtime']))->modify('+1 year')->format('U'))
        ->and($domain->fresh()->expiry_date->toDateString())->toBe(CarbonImmutable::createFromTimestamp((int) $renewed['endtime'])->toDateString())
        ->and($this->resellerclub->log()->count('renew'))->toBe(1);
});
