<?php

use App\Enums\ReconcileStatus;
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

/** Re-review probes for PR #335 (rr335). */
beforeEach(function () {
    $this->scenario = Processes::start(ResellerClubSimulator::class);
    config([
        'integrations.outbound.allow_loopback' => true,
        'integrations.endpoints.overrides' => $this->scenario->simulator()->appConfiguration($this->scenario)['env']['INTEGRATION_ENDPOINTS'],
        'integrations.endpoints.ca_bundle' => $this->scenario->ca()->bundlePath(),
    ]);
    app()->forgetInstance(Endpoints::class);
    app()->instance(ResellerClubClient::class, new ResellerClubClient(3));
    rr335Account(['customer_mode' => 'per_client']);
});

afterEach(function () {
    Processes::stop($this->scenario);
});

function rr335Account(array $settings): void
{
    RegistrarAccount::query()->updateOrCreate(['key' => ResellerClubDriver::KEY], ['is_active' => true, 'test_mode' => true, 'settings' => [
        'demo_reseller_id' => ResellerClubSimulator::RESELLER_ID, 'demo_api_key' => ResellerClubSimulator::API_KEY, ...$settings,
    ]]);
}

function rr335DefaultCustomer(): string
{
    $customer = (string) app(ResellerClubClient::class)->post(app(RegistrarManager::class)->config(ResellerClubDriver::KEY), 'customers/v2/signup.json', [
        'username' => 'host@example.test', 'passwd' => 'HostPass2026', 'name' => 'Host Admin', 'company' => 'Example Hosting', 'address-line-1' => '1 Main Road',
        'city' => 'Lalitpur', 'state' => 'Bagmati', 'country' => 'NP', 'zipcode' => '44700', 'phone-cc' => '977', 'phone' => '15550199', 'lang-pref' => 'en',
    ]);
    rr335Account(['customer_mode' => 'default_customer', 'default_customer_id' => $customer]);

    return $customer;
}

function rr335Client(string $email, string $first = 'Asha'): Client
{
    return Client::factory()->create(['company_name' => null, 'first_name' => $first, 'last_name' => 'Shrestha', 'email' => $email, 'phone' => '01-5550100', 'address_1' => '1 Durbar Marg', 'city' => 'Kathmandu', 'state' => 'Bagmati', 'postcode' => '44600', 'country' => 'NP']);
}

function rr335Domain(string $sld, Client $client, string $type = 'register', ?string $eppCode = null): Domain
{
    $tld = Tld::query()->where('extension', '.com')->first() ?? Tld::factory()->extension('.com')->priced('NPR', 150_000, 140_000, 160_000)->create(['registrar_key' => ResellerClubDriver::KEY]);
    $domain = Domain::factory()->pending($type)->named($sld, $tld)->create(['client_id' => $client->id, 'registrar_key' => ResellerClubDriver::KEY, 'currency' => 'NPR', 'epp_code' => $eppCode, 'nameservers' => ['ns1.host.example.test', 'ns2.host.example.test']]);
    app(BuildDomainContacts::class)->forNewDomain($domain, $client);

    return $domain->fresh();
}

function rr335Config()
{
    return app(RegistrarManager::class)->config(ResellerClubDriver::KEY);
}

test('rr335 B1 default customer: register and transfer of a name another order holds are refused, nothing sent or adopted', function () {
    rr335DefaultCustomer();
    $victim = rr335Domain('victim-sim', rr335Client('victim@example.test'));
    app(SubmitToRegistrar::class)->handle($victim);
    $order = (new ResellerClubApi($this->scenario))->orderByName('victim-sim.com');
    $victim->forceFill(['status' => DomainStatus::Cancelled])->save();

    $reg = rr335Domain('victim-sim', rr335Client('mallory@example.test', 'Mallory'));
    $result = app(SubmitToRegistrar::class)->handle($reg);
    expect($result->success)->toBeFalse()
        ->and($reg->fresh()->status)->toBe(DomainStatus::Pending)
        ->and($reg->fresh()->registrar_meta['remote']['order_id'] ?? null)->toBeNull()
        ->and($this->scenario->log()->count('register'))->toBe(1);
    $reg->forceFill(['status' => DomainStatus::Cancelled])->save();

    $xfer = rr335Domain('victim-sim', rr335Client('eve@example.test', 'Eve'), 'transfer', 'not-the-code');
    app(SubmitToRegistrar::class)->handle($xfer);
    expect($xfer->fresh()->status)->toBe(DomainStatus::Pending)
        ->and($xfer->fresh()->registrar_meta['remote']['order_id'] ?? null)->toBeNull()
        ->and(fn () => app(ResellerClubDriver::class)->getEppCode(rr335Config(), $xfer->fresh()->toRef()))->toThrow(RegistrarException::class);

    // findSubmission for a claim made now, on a name another (older) order of the default customer holds: Conflict.
    $lookup = app(ResellerClubDriver::class)->findSubmission(rr335Config(), $xfer->fresh()->toRef(), 'register', now()->toImmutable()->addMinutes(5));
    expect($lookup->status)->toBe(ReconcileStatus::Conflict);
});

test('rr335 B1 sync: a staff sync of the refused pending row (endpoint accepts any status) links the other order; a resubmit then completes the takeover', function () {
    rr335DefaultCustomer();
    $victim = rr335Domain('victim-sim', rr335Client('victim@example.test'));
    app(SubmitToRegistrar::class)->handle($victim);
    $order = (new ResellerClubApi($this->scenario))->orderByName('victim-sim.com');
    $victim->forceFill(['status' => DomainStatus::Cancelled])->save();

    $xfer = rr335Domain('victim-sim', rr335Client('eve@example.test', 'Eve'), 'transfer', 'not-the-code');
    app(SubmitToRegistrar::class)->handle($xfer);
    app(SyncDomain::class)->handle($xfer->fresh());
    $linked = $xfer->fresh()->registrar_meta['remote']['order_id'] ?? null;
    app(SubmitToRegistrar::class)->handle($xfer->fresh()); // what ResubmitTransfer's TransferDomain job runs
    app(SyncDomain::class)->handle($xfer->fresh());
    expect($linked)->toBeNull()
        ->and($xfer->fresh()->status)->not->toBe(DomainStatus::Active)
        ->and($xfer->fresh()->registrar_meta['remote']['order_id'] ?? null)->not->toBe((int) $order['orderid']);
});

test('rr335 B1 slack: findSubmission adopts an order the same default customer placed up to 120 s before the claim (observation)', function () {
    rr335DefaultCustomer();
    $other = rr335Domain('race-sim', rr335Client('other@example.test', 'Other'));
    app(SubmitToRegistrar::class)->handle($other);
    $order = (new ResellerClubApi($this->scenario))->orderByName('race-sim.com');
    $other->forceFill(['status' => DomainStatus::Cancelled])->save();

    $mine = rr335Domain('race-sim', rr335Client('mine@example.test', 'Mine'));
    $lookup = app(ResellerClubDriver::class)->findSubmission(rr335Config(), $mine->toRef(), 'register', now()->toImmutable()->addSeconds(60));
    expect($lookup->status)->toBe(ReconcileStatus::Executed);
});

test('rr335 details: a domain with no recorded order id reads nothing and links nothing', function () {
    $victim = rr335Domain('victim-sim', rr335Client('victim@example.test'));
    app(SubmitToRegistrar::class)->handle($victim);
    $order = (new ResellerClubApi($this->scenario))->orderByName('victim-sim.com');
    $victim->forceFill(['status' => DomainStatus::Cancelled])->save();

    // A row with no order id for a client with no ResellerClub customer yet (an import, or a row staff set Active).
    $row = rr335Domain('victim-sim', rr335Client('mallory@example.test', 'Mallory'));
    $row->forceFill(['status' => DomainStatus::Active])->save();

    expect(fn () => app(ResellerClubDriver::class)->getEppCode(rr335Config(), $row->fresh()->toRef()))->toThrow(RegistrarException::class, 'No ResellerClub order is linked')
        ->and(app(SyncDomain::class)->handle($row->fresh())['error'])->toContain('No ResellerClub order is linked')
        ->and($row->fresh()->registrar_meta['remote']['order_id'] ?? null)->toBeNull();
});

test('rr335 B4: after a lost renewal that ResellerClub ran, a repeat takes its expiry and orders nothing; the paid job agrees', function () {
    $domain = rr335Domain('asha-sim', rr335Client('asha@example.test'));
    app(SubmitToRegistrar::class)->handle($domain);
    $domain = $domain->fresh();
    $orig = $domain->expiry_date->toDateString();
    $ref = $domain->toRef();

    app(ResellerClubDriver::class)->renew(rr335Config(), $ref, 1);
    $balance = (int) $this->scenario->state()->get('config', 'balance');

    $repeat = app(ResellerClubDriver::class)->renew(rr335Config(), $ref, 1);
    expect($repeat->status)->toBe('completed')
        ->and($repeat->expiryDate->toDateString())->not->toBe($orig)
        ->and($repeat->message)->not->toContain('sync the domain first')
        ->and((int) $this->scenario->state()->get('config', 'balance'))->toBe($balance)
        ->and($this->scenario->log()->count('renew'))->toBe(1);

    // The paid job (and a staff renew from the old expiry) applies ResellerClub's date without a second call.
    $job = app(PerformRenewal::class)->handle($domain->fresh(), 1, $orig);
    expect($job->success)->toBeTrue()
        ->and($domain->fresh()->expiry_date->toDateString())->toBe($repeat->expiryDate->toDateString())
        ->and($this->scenario->log()->count('renew'))->toBe(1)
        ->and((int) $this->scenario->state()->get('config', 'balance'))->toBe($balance);
});

test('rr335 B4 sync: sync alone brings our expiry to the one ResellerClub renewed to', function () {
    $domain = rr335Domain('beta-sim', rr335Client('beta@example.test', 'Beta'));
    app(SubmitToRegistrar::class)->handle($domain);
    $before = $domain->fresh()->expiry_date->toDateString();
    app(ResellerClubDriver::class)->renew(rr335Config(), $domain->fresh()->toRef(), 1);

    app(SyncDomain::class)->handle($domain->fresh());

    expect($domain->fresh()->expiry_date->toDateString())->not->toBe($before)
        ->and($this->scenario->log()->count('renew'))->toBe(1);
});

test('rr335 B3/stall: a pending registration with no order id is reported by sync, and listed in Needs attention after 15 minutes', function () {
    $domain = rr335Domain('lost-sim', rr335Client('asha@example.test'));
    // As succeeded() leaves it after a non-confirming answer without an order id: no claim, no review flag.
    $domain->forceFill(['status' => DomainStatus::PendingRegistration, 'needs_review' => false, 'registrar_meta' => ['submitted_at' => now()->toIso8601String()]])->save();

    $early = app(SyncDomain::class)->handle($domain->fresh());
    expect($early['error'])->toContain('Registration not confirmed at ResellerClub')
        ->and($domain->fresh()->last_sync_status)->toBe('error')
        ->and($domain->fresh()->needs_review)->toBeFalse();

    $this->travel(16)->minutes();
    app(SyncDomain::class)->handle($domain->fresh());

    expect($domain->fresh()->status)->toBe(DomainStatus::PendingRegistration)
        ->and($domain->fresh()->needs_review)->toBeTrue()
        ->and($domain->fresh()->last_error)->toContain('Registration not confirmed at ResellerClub');
});

test('rr335 lost register then manual re-register: conflict, staff told, and sync links nothing', function () {
    $domain = rr335Domain('asha-sim', rr335Client('asha@example.test'));
    app(SubmitToRegistrar::class)->handle($domain);
    $order = (new ResellerClubApi($this->scenario))->orderByName('asha-sim.com');
    // Simulate a lost answer: the host never learnt the order id and the row is back to Pending without a claim.
    $domain->forceFill(['status' => DomainStatus::Pending, 'registrar_meta' => null, 'expiry_date' => null])->save();

    $result = app(SubmitToRegistrar::class)->handle($domain->fresh());
    expect($result->success)->toBeFalse()->and($this->scenario->log()->count('register'))->toBe(1);

    $domain->forceFill(['status' => DomainStatus::Active])->save();
    $sync = app(SyncDomain::class)->handle($domain->fresh());

    // Sync never links an order it finds by searching; staff links it by hand.
    expect($sync['error'])->toContain('No ResellerClub order is linked')
        ->and($domain->fresh()->registrar_meta['remote']['order_id'] ?? null)->toBeNull();
});

test('rr335 M6: a wrong code handed to our own transfer order that waits for one is reported, not called "already sent"', function () {
    $this->scenario->simulator()->actions()['add_foreign_domain'](['domain' => 'move-sim.com', 'auth_code' => 'right-code'], $this->scenario);
    $domain = rr335Domain('move-sim', rr335Client('asha@example.test'), 'transfer');
    app(SubmitToRegistrar::class)->handle($domain);
    $order = (new ResellerClubApi($this->scenario))->orderByName('move-sim.com');

    // The client resubmits with a mistyped code; the host holds the order id.
    $domain->forceFill(['status' => DomainStatus::Pending, 'epp_code' => 'wrong-code'])->save();
    $result = app(SubmitToRegistrar::class)->handle($domain->fresh());
    expect($order['transfer_stage'])->toBe('awaiting_auth_code')
        ->and($result->success)->toBeFalse()
        ->and($result->message)->toContain('not valid')
        ->and($result->message)->not->toContain('already sent');
});
