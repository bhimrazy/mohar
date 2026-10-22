<?php

use App\Enums\ReconcileStatus;
use App\Modules\Core\Models\Client;
use App\Modules\Domains\Actions\BuildDomainContacts;
use App\Modules\Domains\Actions\SubmitToRegistrar;
use App\Modules\Domains\Actions\SyncDomain;
use App\Modules\Domains\Drivers\Registrars\ResellerClub\ResellerClubClient;
use App\Modules\Domains\Drivers\Registrars\ResellerClub\ResellerClubContacts;
use App\Modules\Domains\Drivers\Registrars\ResellerClub\ResellerClubDriver;
use App\Modules\Domains\Enums\DomainStatus;
use App\Modules\Domains\Models\Domain;
use App\Modules\Domains\Models\RegistrarAccount;
use App\Modules\Domains\Models\Tld;
use App\Modules\Domains\Services\RegistrarManager;
use App\Support\Domains\RegistrarException;
use App\Support\Integrations\Endpoints;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mohar\Simulators\Kernel\Processes;
use Mohar\Simulators\Providers\ResellerClub\ResellerClubApi;
use Mohar\Simulators\Providers\ResellerClub\ResellerClubSimulator;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Review-board probes for PR #335. Each asserts the behaviour the review asks for, so each fails on e2a84b0e.
 */
beforeEach(function () {
    $this->scenario = Processes::start(ResellerClubSimulator::class);
    config([
        'integrations.outbound.allow_loopback' => true,
        'integrations.endpoints.overrides' => $this->scenario->simulator()->appConfiguration($this->scenario)['env']['INTEGRATION_ENDPOINTS'],
        'integrations.endpoints.ca_bundle' => $this->scenario->ca()->bundlePath(),
    ]);
    app()->forgetInstance(Endpoints::class);
    app()->instance(ResellerClubClient::class, new ResellerClubClient(3));
    RegistrarAccount::query()->updateOrCreate(['key' => ResellerClubDriver::KEY], ['is_active' => true, 'test_mode' => true, 'settings' => [
        'demo_reseller_id' => ResellerClubSimulator::RESELLER_ID, 'demo_api_key' => ResellerClubSimulator::API_KEY, 'customer_mode' => 'per_client',
    ]]);
});

afterEach(function () {
    Processes::stop($this->scenario);
});

function rb335Client(string $email, string $first = 'Asha'): Client
{
    return Client::factory()->create(['company_name' => null, 'first_name' => $first, 'last_name' => 'Shrestha', 'email' => $email, 'phone' => '01-5550100', 'address_1' => '1 Durbar Marg', 'city' => 'Kathmandu', 'state' => 'Bagmati', 'postcode' => '44600', 'country' => 'NP']);
}

function rb335Domain(string $sld, Client $client, string $type = 'register', ?string $eppCode = null): Domain
{
    $tld = Tld::query()->where('extension', '.com')->first() ?? Tld::factory()->extension('.com')->priced('NPR', 150_000, 140_000, 160_000)->create(['registrar_key' => ResellerClubDriver::KEY]);
    $domain = Domain::factory()->pending($type)->named($sld, $tld)->create(['client_id' => $client->id, 'registrar_key' => ResellerClubDriver::KEY, 'currency' => 'NPR', 'epp_code' => $eppCode, 'nameservers' => ['ns1.host.example.test', 'ns2.host.example.test']]);
    app(BuildDomainContacts::class)->forNewDomain($domain, $client);

    return $domain->fresh();
}

test('B1: a transfer-in with a wrong code never adopts an order of another customer in the reseller account', function () {
    $victim = rb335Domain('victim-sim', rb335Client('victim@example.test'));
    app(SubmitToRegistrar::class)->handle($victim);
    $order = (new ResellerClubApi($this->scenario))->orderByName('victim-sim.com');
    // The host no longer tracks it (cancelled locally, or a name the reseller placed in the RC panel): active_name frees up.
    $victim->forceFill(['status' => DomainStatus::Cancelled])->save();

    $attacker = rb335Domain('victim-sim', rb335Client('mallory@example.test', 'Mallory'), 'transfer', 'not-the-code');
    app(SubmitToRegistrar::class)->handle($attacker);
    app(SyncDomain::class)->handle($attacker->fresh());

    // The attacker's row must not read the victim's transfer code either.
    expect(fn () => app(ResellerClubDriver::class)->getEppCode(app(RegistrarManager::class)->config(ResellerClubDriver::KEY), $attacker->fresh()->toRef()))->toThrow(RegistrarException::class);
    expect($attacker->fresh()->registrar_meta['remote']['order_id'] ?? null)->not->toBe((int) $order['orderid']);
});

test('B2: per-client mode never adopts another client\'s ResellerClub customer by a typed registrant email', function () {
    $victim = rb335Domain('victim-sim', rb335Client('victim@example.test'));
    app(SubmitToRegistrar::class)->handle($victim);
    $victimCustomer = (new ResellerClubApi($this->scenario))->orderByName('victim-sim.com')['customerid'];

    $mallory = rb335Domain('mallory-sim', rb335Client('mallory@example.test', 'Mallory'));
    $config = app(RegistrarManager::class)->config(ResellerClubDriver::KEY);
    // Mallory's registrant contact carries the victim's (unverified) email address.
    $contacts = app(BuildDomainContacts::class)->contactSet($victim);

    $customer = app(ResellerClubContacts::class)->customerId($config, $mallory->toRef(), $contacts);

    expect($customer)->not->toBe((string) $victimCustomer);
});

test('B3: sync never cancels a domain when the registry availability check answers unknown', function () {
    $domain = rb335Domain('ghost-sim', rb335Client('asha@example.test'));
    $domain->forceFill(['status' => DomainStatus::Active, 'expiry_date' => now()->addMonths(6)->toDateString()])->save();
    $this->scenario->simulator()->actions()['registry_down'](['tld' => 'com', 'down' => true], $this->scenario);

    app(SyncDomain::class)->handle($domain->fresh());

    expect($domain->fresh()->status)->toBe(DomainStatus::Active);
});

test('M1: findSubmission(renew) does not report Executed before any renewal ran', function () {
    $domain = rb335Domain('asha-sim', rb335Client('asha@example.test'));
    app(SubmitToRegistrar::class)->handle($domain);
    $config = app(RegistrarManager::class)->config(ResellerClubDriver::KEY);

    $lookup = app(ResellerClubDriver::class)->findSubmission($config, $domain->fresh()->toRef(), 'renew', now()->toImmutable());

    expect($this->scenario->log()->count('renew'))->toBe(0)
        ->and($lookup->status)->toBe(ReconcileStatus::NotExecuted);
});

test('B4: a second renew() for the same host expiry takes ResellerClub\'s expiry and orders nothing (no second charge)', function () {
    $domain = rb335Domain('asha-sim', rb335Client('asha@example.test'));
    app(SubmitToRegistrar::class)->handle($domain);
    $config = app(RegistrarManager::class)->config(ResellerClubDriver::KEY);
    $ref = $domain->fresh()->toRef();

    app(ResellerClubDriver::class)->renew($config, $ref, 1);
    $balance = (int) $this->scenario->state()->get('config', 'balance');

    expect(app(ResellerClubDriver::class)->renew($config, $ref, 1)->status)->toBe('completed');
    expect($this->scenario->log()->count('renew'))->toBe(1);
    expect((int) $this->scenario->state()->get('config', 'balance'))->toBe($balance);
});
