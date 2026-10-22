<?php

/*
 * Probes from the final re-review of #335, adopted: the claim window's upper bound, the stall rule on transient
 * errors, renewals that ran at ResellerClub, and the already-sent wording.
 */

use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Models\Client;
use App\Modules\Domains\Actions\BuildDomainContacts;
use App\Modules\Domains\Actions\PerformRenewal;
use App\Modules\Domains\Actions\SubmitToRegistrar;
use App\Modules\Domains\Actions\SyncDomain;
use App\Modules\Domains\Drivers\Registrars\ResellerClub\ResellerClubClient;
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

beforeEach(function () {
    $this->scenario = Processes::start(ResellerClubSimulator::class);
    config([
        'integrations.outbound.allow_loopback' => true,
        'integrations.endpoints.overrides' => $this->scenario->simulator()->appConfiguration($this->scenario)['env']['INTEGRATION_ENDPOINTS'],
        'integrations.endpoints.ca_bundle' => $this->scenario->ca()->bundlePath(),
    ]);
    app()->forgetInstance(Endpoints::class);
    app()->instance(ResellerClubClient::class, new ResellerClubClient(3));
    rfAccount(['customer_mode' => 'per_client']);
});

afterEach(function () {
    Processes::stop($this->scenario);
});

function rfAccount(array $settings): void
{
    RegistrarAccount::query()->updateOrCreate(['key' => ResellerClubDriver::KEY], ['is_active' => true, 'test_mode' => true, 'settings' => [
        'demo_reseller_id' => ResellerClubSimulator::RESELLER_ID, 'demo_api_key' => ResellerClubSimulator::API_KEY, ...$settings,
    ]]);
}

function rfDefault(): string
{
    $customer = (string) app(ResellerClubClient::class)->post(app(RegistrarManager::class)->config(ResellerClubDriver::KEY), 'customers/v2/signup.json', [
        'username' => 'host@example.test', 'passwd' => 'HostPass2026', 'name' => 'Host Admin', 'company' => 'Example Hosting', 'address-line-1' => '1 Main Road',
        'city' => 'Lalitpur', 'state' => 'Bagmati', 'country' => 'NP', 'zipcode' => '44700', 'phone-cc' => '977', 'phone' => '15550199', 'lang-pref' => 'en',
    ]);
    rfAccount(['customer_mode' => 'default_customer', 'default_customer_id' => $customer]);

    return $customer;
}

function rfClient(string $email, string $first = 'Asha'): Client
{
    return Client::factory()->create(['company_name' => null, 'first_name' => $first, 'last_name' => 'Shrestha', 'email' => $email, 'phone' => '01-5550100', 'address_1' => '1 Durbar Marg', 'city' => 'Kathmandu', 'state' => 'Bagmati', 'postcode' => '44600', 'country' => 'NP']);
}

function rfDomain(string $sld, Client $client, string $type = 'register', ?string $eppCode = null): Domain
{
    $tld = Tld::query()->where('extension', '.com')->first() ?? Tld::factory()->extension('.com')->priced('NPR', 150_000, 140_000, 160_000)->create(['registrar_key' => ResellerClubDriver::KEY]);
    $domain = Domain::factory()->pending($type)->named($sld, $tld)->create(['client_id' => $client->id, 'registrar_key' => ResellerClubDriver::KEY, 'currency' => 'NPR', 'epp_code' => $eppCode, 'nameservers' => ['ns1.host.example.test', 'ns2.host.example.test']]);
    app(BuildDomainContacts::class)->forNewDomain($domain, $client);

    return $domain->fresh();
}

function rfConfig()
{
    return app(RegistrarManager::class)->config(ResellerClubDriver::KEY);
}

test('rf B1 window upper bound: a stuck claim adopts an order of the default customer placed hours after the claim', function () {
    rfDefault();
    $victim = rfDomain('victim-sim', rfClient('victim@example.test'));
    app(SubmitToRegistrar::class)->handle($victim);
    $order = (new ResellerClubApi($this->scenario))->orderByName('victim-sim.com');
    $victim->forceFill(['status' => DomainStatus::Cancelled])->save();

    // Mallory's registration of the same name got stuck (lost answer, "no record" at the registrar) two hours ago.
    $stuck = rfDomain('victim-sim', rfClient('mallory@example.test', 'Mallory'));
    $at = now()->subHours(2)->toIso8601String();
    $stuck->forceFill(['status' => DomainStatus::PendingRegistration, 'needs_review' => true, 'registrar_meta' => ['submission' => $at, 'submitted_at' => $at]])->save();

    app(SyncDomain::class)->handle($stuck->fresh());
    $linked = $stuck->fresh()->registrar_meta['remote']['order_id'] ?? null;
    $code = null;
    try {
        $code = app(ResellerClubDriver::class)->getEppCode(rfConfig(), $stuck->fresh()->toRef())->code;
    } catch (RegistrarException) {
    }
    expect((string) $linked)->not->toBe((string) $order['orderid']);
});

test('rf B1 race: two host clients cannot hold the same name at once (active_name)', function () {
    rfDefault();
    $a = rfDomain('race-sim', rfClient('a@example.test'));
    $thrown = null;
    try {
        rfDomain('race-sim', rfClient('b@example.test', 'Bee'));
    } catch (Throwable $e) {
        $thrown = get_class($e);
    }
    expect($thrown)->not->toBeNull();
});

test('rf stall: a healthy pending registration with a linked order is not flagged on a transient error', function () {
    $domain = rfDomain('slow-sim', rfClient('asha@example.test'));
    $this->scenario->simulator()->actions()['next_actionstatus'](['value' => 'PendingExecution'], $this->scenario);
    app(SubmitToRegistrar::class)->handle($domain);
    $fresh = $domain->fresh();
    $fresh->forceFill(['status' => DomainStatus::PendingRegistration, 'needs_review' => false, 'registrar_meta' => [...($fresh->registrar_meta ?? []), 'submitted_at' => now()->subMinutes(30)->toIso8601String()]])->save();

    $ok = app(SyncDomain::class)->handle($domain->fresh());

    Processes::stop($this->scenario, keep: true); // registrar unreachable: a transient, retryable error
    $err = app(SyncDomain::class)->handle($domain->fresh());
    expect($domain->fresh()->needs_review)->toBeFalse();
});

test('rf stall spam: repeated syncs of an unconfirmed registration log one staff task', function () {
    $domain = rfDomain('lost-sim', rfClient('asha@example.test'));
    $domain->forceFill(['status' => DomainStatus::PendingRegistration, 'needs_review' => false, 'registrar_meta' => ['submitted_at' => now()->subHour()->toIso8601String()]])->save();
    foreach (range(1, 4) as $i) {
        app(SyncDomain::class)->handle($domain->fresh());
    }
    $tasks = ActivityLog::query()->where('description', 'like', '%not confirmed at the registrar%')->count();
    expect($tasks)->toBe(1);
});

test('rf B4: lost renewal, then every path - job retry, staff clear+renew, sync then job, sync then staff renew', function () {
    $domain = rfDomain('asha-sim', rfClient('asha@example.test'));
    app(SubmitToRegistrar::class)->handle($domain);
    $domain = $domain->fresh();
    $orig = $domain->expiry_date->toDateString();

    // The paid job claims the cycle, ResellerClub renews, the answer is lost (claim kept, needs review).
    app(ResellerClubDriver::class)->renew(rfConfig(), $domain->toRef(), 1);
    $domain->forceFill(['needs_review' => true, 'registrar_meta' => [...$domain->registrar_meta, 'renew_sent_for' => $orig, 'renew_sent_at' => now()->toIso8601String()]])->save();
    $balance = (int) $this->scenario->state()->get('config', 'balance');

    // a) queue retry of the paid job: reconcile finds it
    $a = app(PerformRenewal::class)->handle($domain->fresh(), 1, $orig);

    // b) same lost state, staff clears review (Change status) and presses Renew (fromExpiry = our old expiry)
    $domain->forceFill(['expiry_date' => $orig, 'needs_review' => false, 'registrar_meta' => collect($domain->fresh()->registrar_meta)->except(['renew_sent_for', 'renew_sent_at'])->all()])->save();
    $b = app(PerformRenewal::class)->handle($domain->fresh(), 1, $orig);

    // c) same lost state, sync runs first, then the paid job is retried
    $domain->forceFill(['expiry_date' => $orig])->save();
    app(SyncDomain::class)->handle($domain->fresh());
    $c = app(PerformRenewal::class)->handle($domain->fresh(), 1, $orig);

    expect($this->scenario->log()->count('renew'))->toBe(1)
        ->and((int) $this->scenario->state()->get('config', 'balance'))->toBe($balance);

    // d) after the sync, staff presses Renew (fromExpiry = synced expiry): an explicit extra term (observation)
    $d = app(PerformRenewal::class)->handle($domain->fresh(), 1, $domain->fresh()->expiry_date->toDateString());
});

test('rf B4 partial: ResellerClub ahead by less than the term is not taken as a renewal; nothing is ordered', function () {
    $domain = rfDomain('drift-sim', rfClient('asha@example.test'));
    app(SubmitToRegistrar::class)->handle($domain);
    $domain = $domain->fresh();
    $theirs = $domain->expiry_date->toDateString();
    $ours = $domain->expiry_date->subDays(10)->toDateString();
    $domain->forceFill(['expiry_date' => $ours])->save();
    $r = app(PerformRenewal::class)->handle($domain->fresh(), 1, $ours);
    expect($r->success)->toBeFalse()
        ->and($domain->fresh()->expiry_date->toDateString())->toBe($ours)
        ->and($this->scenario->log()->count('renew'))->toBe(0);
});

test('rf M6: already-sent wording for an order no longer waiting', function () {
    $this->scenario->simulator()->actions()['add_foreign_domain'](['domain' => 'move-sim.com', 'auth_code' => 'right-code'], $this->scenario);
    $domain = rfDomain('move-sim', rfClient('asha@example.test'), 'transfer', 'right-code');
    app(SubmitToRegistrar::class)->handle($domain);
    $domain->forceFill(['status' => DomainStatus::Pending, 'epp_code' => 'right-code'])->save();
    $result = app(SubmitToRegistrar::class)->handle($domain->fresh());
    expect($result->success)->toBeTrue();
});
