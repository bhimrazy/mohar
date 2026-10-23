<?php

use App\Data\Domains\Registrar\ContactSet;
use App\Data\Domains\Registrar\DomainRef;
use App\Data\Domains\Registrar\RegistrarConfig;
use App\Data\Domains\Registrar\RegistrationRequest;
use App\Enums\ReconcileStatus;
use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Models\Client;
use App\Modules\Domains\Actions\BuildDomainContacts;
use App\Modules\Domains\Actions\PerformRenewal;
use App\Modules\Domains\Actions\SetRegistrarLock;
use App\Modules\Domains\Actions\SubmitToRegistrar;
use App\Modules\Domains\Actions\SyncDomain;
use App\Modules\Domains\Actions\UpdateNameservers;
use App\Modules\Domains\Drivers\Registrars\Namecheap\NamecheapClient;
use App\Modules\Domains\Drivers\Registrars\Namecheap\NamecheapDriver;
use App\Modules\Domains\Enums\DomainStatus;
use App\Modules\Domains\Models\Domain;
use App\Modules\Domains\Models\RegistrarAccount;
use App\Modules\Domains\Models\RegistrarCommandLog;
use App\Modules\Domains\Models\Tld;
use App\Modules\Domains\Services\RegistrarGateway;
use App\Modules\Domains\Services\RegistrarManager;
use App\Support\Domains\RegistrarException;
use App\Support\Domains\RegistrarTimeout;
use App\Support\Integrations\Endpoints;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Mohar\Simulators\Kernel\Fault;
use Mohar\Simulators\Kernel\Processes;
use Mohar\Simulators\Providers\Namecheap\NamecheapApi;
use Mohar\Simulators\Providers\Namecheap\NamecheapSimulator;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * The Namecheap driver, unchanged, against the namecheap simulator over TLS, pinned by the outbound guard (ADR 0016
 * §3), through the Domains host: submit, renew, sync, transfer, name servers, lock, privacy, contacts, restore, test
 * connection. No Http::fake. Each scenario the driver must survive: success, unavailable, premium, insufficient funds,
 * an async registration, a transfer waiting on the other registrar, an unknown outcome and a timeout after commit,
 * a rate limit, the address allow-list.
 */
beforeEach(function () {
    $this->scenario = Processes::start(NamecheapSimulator::class);
    config([
        'integrations.outbound.allow_loopback' => true,
        'integrations.endpoints.overrides' => $this->scenario->simulator()->appConfiguration($this->scenario)['env']['INTEGRATION_ENDPOINTS'],
        'integrations.endpoints.ca_bundle' => $this->scenario->ca()->bundlePath(),
    ]);
    app()->forgetInstance(Endpoints::class);
    // A short client timeout, so a hung answer is given up on quickly; the simulator holds it far longer.
    app()->instance(NamecheapClient::class, new NamecheapClient(3));
    RateLimiter::clear('namecheap:sandbox:'.sha1(NamecheapSimulator::USER_NAME).':60');
    ncAccount();
});

afterEach(function () {
    Processes::stop($this->scenario);
});

/**
 * @param  array<string, mixed>  $settings
 */
function ncAccount(array $settings = []): void
{
    RegistrarAccount::query()->updateOrCreate(['key' => NamecheapDriver::KEY], ['is_active' => true, 'test_mode' => true, 'settings' => [
        'sandbox_api_user' => NamecheapSimulator::USER_NAME, 'sandbox_api_key' => NamecheapSimulator::API_KEY, 'client_ip' => NamecheapSimulator::CLIENT_IP, ...$settings,
    ]]);
}

/** A pending domain of a Kathmandu client, with its contacts, sold through Namecheap. */
function ncDomain(string $sld, string $extension = '.com', string $type = 'register', ?Client $client = null, ?string $eppCode = null): Domain
{
    $tld = Tld::query()->where('extension', $extension)->first() ?? Tld::factory()->extension($extension)->priced('NPR', 150_000, 140_000, 160_000)->create(['registrar_key' => NamecheapDriver::KEY]);
    $client ??= Client::factory()->create(['company_name' => null, 'first_name' => 'Asha', 'last_name' => 'Shrestha', 'email' => 'asha@example.test', 'phone' => '01-5550100', 'address_1' => '1 Durbar Marg', 'city' => 'Kathmandu', 'state' => 'Bagmati', 'postcode' => '44600', 'country' => 'NP']);
    $domain = Domain::factory()->pending($type)->named($sld, $tld)->create(['client_id' => $client->id, 'registrar_key' => NamecheapDriver::KEY, 'currency' => 'NPR', 'epp_code' => $eppCode, 'nameservers' => ['ns1.host.example.test', 'ns2.host.example.test']]);
    app(BuildDomainContacts::class)->forNewDomain($domain, $client);

    return $domain->fresh();
}

/** @return array<string, mixed>|null */
function ncRecord(string $name): ?array
{
    return (new NamecheapApi(test()->scenario))->domain($name);
}

function ncConfigured(): RegistrarConfig
{
    return app(RegistrarManager::class)->config(NamecheapDriver::KEY);
}

test('a registration completes with the ids and the term\'s expiry; the sync then takes the registry date', function () {
    $domain = ncDomain('asha-sim');
    $result = app(SubmitToRegistrar::class)->handle($domain);
    $record = ncRecord('asha-sim.com');
    $domain->refresh();

    expect($result->success)->toBeTrue()
        ->and($domain->status)->toBe(DomainStatus::Active)
        ->and($domain->registrar_meta['remote'])->toBe(['domain_id' => (int) $record['id'], 'order_id' => (int) $record['order']])
        ->and($domain->expiry_date->toDateString())->toBe(CarbonImmutable::createFromTimestamp($record['expires'])->toDateString())
        ->and($record['nameservers'])->toBe(['ns1.host.example.test', 'ns2.host.example.test'])
        ->and($record['contacts']['AuxBilling'])->toMatchArray(['FirstName' => 'Asha', 'Phone' => '+977.15550100', 'Country' => 'NP', 'EmailAddress' => 'asha@example.test'])
        ->and($this->scenario->log()->count('domains_create'))->toBe(1)
        ->and((string) json_encode(RegistrarCommandLog::query()->get(['request', 'response', 'error'])))->not->toContain(NamecheapSimulator::API_KEY);

    $this->scenario->simulator()->actions()['expire'](['domain' => 'asha-sim.com', 'days_ago' => -400], $this->scenario);
    $sync = app(SyncDomain::class)->handle($domain->fresh());

    expect($sync['error'])->toBeNull()
        ->and($domain->fresh()->expiry_date->toDateString())->toBe(CarbonImmutable::createFromTimestamp(ncRecord('asha-sim.com')['expires'])->toDateString());
});

test('a name held elsewhere, a premium name and an empty balance are refused; the domain stays pending with the reason', function () {
    $taken = ncDomain('taken-elsewhere');
    $premium = ncDomain('premium-gold', client: $taken->client);
    app(SubmitToRegistrar::class)->handle($taken);
    app(SubmitToRegistrar::class)->handle($premium);
    $this->scenario->simulator()->actions()['set_balance'](['cents' => 100], $this->scenario);
    $broke = ncDomain('asha-sim', client: $taken->client);
    app(SubmitToRegistrar::class)->handle($broke);

    $availability = [...app(NamecheapDriver::class)->checkAvailability(ncConfigured(), 'premium-gold', ['.com']), ...app(NamecheapDriver::class)->checkAvailability(ncConfigured(), 'asha-free', ['.net', '.in', '.invalid'])];

    expect($taken->fresh()->status)->toBe(DomainStatus::Pending)
        ->and($taken->fresh()->last_error)->toContain('3019166')
        ->and($premium->fresh()->status)->toBe(DomainStatus::Pending)
        ->and($premium->fresh()->last_error)->toContain('premium')
        ->and($broke->fresh()->last_error)->toContain('no funds')
        ->and($this->scenario->state()->all('domains'))->toBe([])
        ->and($availability[0]->isPremium)->toBeTrue()
        ->and($availability[0]->premiumRegisterCost)->toBe(NamecheapApi::PREMIUM_PRICE)
        ->and([$availability[1]->domain, $availability[1]->status, $availability[1]->isPremium])->toBe(['asha-free.net', 'available', false])
        ->and($availability[2]->domain)->toBe('asha-free.in')
        ->and($availability[3]->status)->toBe('unknown');
});

test('an async registration waits as Pending registration, then sync makes it active', function () {
    $domain = ncDomain('slow-asha');

    app(SubmitToRegistrar::class)->handle($domain);
    expect($domain->fresh()->status)->toBe(DomainStatus::PendingRegistration);

    app(SyncDomain::class)->handle($domain->fresh());
    expect($domain->fresh()->status)->toBe(DomainStatus::PendingRegistration);

    $this->scenario->simulator()->actions()['settle_order'](['domain' => 'slow-asha.com'], $this->scenario);
    $sync = app(SyncDomain::class)->handle($domain->fresh());

    expect($sync['error'])->toBeNull()
        ->and($domain->fresh()->status)->toBe(DomainStatus::Active)
        ->and($domain->fresh()->expiry_date)->not->toBeNull()
        ->and($this->scenario->log()->count('domains_create'))->toBe(1);
});

test('a registration whose answer is lost waits for review, and the reconcile finds the name without sending it again', function () {
    $this->scenario->faults()->add('domains_create', Fault::commitThenHang(30), nth: 1);
    $domain = ncDomain('asha-sim');

    app(SubmitToRegistrar::class)->handle($domain);
    expect($domain->fresh()->needs_review)->toBeTrue()
        ->and($domain->fresh()->status)->toBe(DomainStatus::PendingRegistration);

    $this->travel(SubmitToRegistrar::IN_FLIGHT_MINUTES + 1)->minutes();
    app(SubmitToRegistrar::class)->handle($domain->fresh());
    $sync = app(SyncDomain::class)->handle($domain->fresh());

    expect($sync['error'])->toBeNull()
        ->and($domain->fresh()->status)->toBe(DomainStatus::Active)
        ->and($domain->fresh()->needs_review)->toBeFalse()
        ->and($domain->fresh()->registrar_meta['remote']['domain_id'])->toBe((int) ncRecord('asha-sim.com')['id'])
        ->and($this->scenario->log()->count('domains_create'))->toBe(1);
});

test('an unknown-outcome error after the work ran is settled the same way, and one before it ran is left for staff', function () {
    $this->scenario->simulator()->actions()['next_error'](['number' => 3011511, 'message' => 'Unknown response from the provider.', 'commit' => true], $this->scenario);
    $ran = ncDomain('ran-sim');
    app(SubmitToRegistrar::class)->handle($ran);

    expect($ran->fresh()->needs_review)->toBeTrue();

    $this->travel(SubmitToRegistrar::IN_FLIGHT_MINUTES + 1)->minutes();
    app(SubmitToRegistrar::class)->handle($ran->fresh());
    app(SyncDomain::class)->handle($ran->fresh());

    expect($ran->fresh()->status)->toBe(DomainStatus::Active)
        ->and($this->scenario->log()->count('domains_create'))->toBe(1);

    $this->scenario->simulator()->actions()['next_error'](['number' => 3011511, 'message' => 'Unknown response from the provider.'], $this->scenario);
    $notRan = ncDomain('not-ran-sim', client: $ran->client);
    app(SubmitToRegistrar::class)->handle($notRan);
    $this->travel(SubmitToRegistrar::IN_FLIGHT_MINUTES + 1)->minutes();
    app(SubmitToRegistrar::class)->handle($notRan->fresh());

    expect($notRan->fresh()->needs_review)->toBeTrue()
        ->and($notRan->fresh()->status)->toBe(DomainStatus::PendingRegistration)
        ->and(ncRecord('not-ran-sim.com'))->toBeNull()
        ->and($this->scenario->log()->count('domains_create'))->toBe(2);
});

test('a register retried by hand after a lost answer never adopts the name unless the host holds its id', function () {
    $this->scenario->faults()->add('domains_create', Fault::commitThenHang(30), nth: 1);
    $domain = ncDomain('asha-sim');
    app(SubmitToRegistrar::class)->handle($domain);
    $ref = $domain->fresh()->toRef();
    $request = fn (DomainRef $ref) => new RegistrationRequest($ref, 1, ['ns1.host.example.test', 'ns2.host.example.test'], app(BuildDomainContacts::class)->contactSet($domain));

    expect(fn () => app(NamecheapDriver::class)->register(ncConfigured(), $request($ref)))->toThrow(RegistrarException::class, 'link it by hand');

    $id = (int) ncRecord('asha-sim.com')['id'];
    $held = app(NamecheapDriver::class)->register(ncConfigured(), $request(new DomainRef(...[...get_object_vars($ref), 'remote' => ['domain_id' => $id]])));

    expect($held->status)->toBe('completed')
        ->and($held->remote['domain_id'])->toBe($id)
        ->and($this->scenario->log()->count('domains_create'))->toBe(1);
});

test('findSubmission adopts a name created since the submission and reports an older one as a conflict', function () {
    $this->scenario->faults()->add('domains_create', Fault::commitThenHang(30), nth: 1);
    $domain = ncDomain('asha-sim');
    $started = now()->toImmutable()->subSeconds(5);
    app(SubmitToRegistrar::class)->handle($domain);
    $ref = $domain->fresh()->toRef();
    $driver = app(NamecheapDriver::class);

    $mine = $driver->findSubmission(ncConfigured(), $ref, 'register', $started);
    $later = $driver->findSubmission(ncConfigured(), $ref, 'register', now()->toImmutable()->addDays(3));

    expect($mine->status)->toBe(ReconcileStatus::Executed)
        ->and($mine->remote['domain_id'])->toBe((int) ncRecord('asha-sim.com')['id'])
        ->and($later->status)->toBe(ReconcileStatus::Conflict)
        ->and($driver->findSubmission(ncConfigured(), new DomainRef(...[...get_object_vars($ref), 'name' => 'nobody.com', 'sld' => 'nobody']), 'register', $started)->status)->toBe(ReconcileStatus::NotExecuted)
        ->and($driver->findSubmission(ncConfigured(), new DomainRef(...[...get_object_vars($ref), 'name' => 'taken-elsewhere.com', 'sld' => 'taken-elsewhere']), 'register', $started)->status)->toBe(ReconcileStatus::Conflict);
});

test('renewal adds the term once; a lost renewal is reconciled from the new expiry and never bought twice', function () {
    $domain = ncDomain('asha-sim');
    app(SubmitToRegistrar::class)->handle($domain);
    $before = ncRecord('asha-sim.com')['expires'];

    app(PerformRenewal::class)->handle($domain->fresh(), 1);
    $after = ncRecord('asha-sim.com')['expires'];

    expect(CarbonImmutable::createFromTimestamp($after)->toDateString())->toBe(CarbonImmutable::createFromTimestamp($before)->addYear()->toDateString())
        ->and($domain->fresh()->expiry_date->toDateString())->toBe(CarbonImmutable::createFromTimestamp($after)->toDateString());

    $this->scenario->faults()->add('domains_renew', Fault::commitThenHang(30), nth: 2);
    app(PerformRenewal::class)->handle($domain->fresh(), 1);
    expect($domain->fresh()->needs_review)->toBeTrue();

    app(PerformRenewal::class)->handle($domain->fresh(), 1);

    expect($this->scenario->log()->count('domains_renew'))->toBe(2)
        ->and($domain->fresh()->expiry_date->toDateString())->toBe(CarbonImmutable::createFromTimestamp(ncRecord('asha-sim.com')['expires'])->toDateString());
});

test('a name that expired is restored with reactivate, and renewing it is refused with the way out', function () {
    $domain = ncDomain('asha-sim');
    app(SubmitToRegistrar::class)->handle($domain);
    $this->scenario->simulator()->actions()['expire'](['domain' => 'asha-sim.com', 'days_ago' => 5], $this->scenario);
    $ref = $domain->fresh()->toRef();
    $driver = app(NamecheapDriver::class);

    expect(fn () => $driver->renew(ncConfigured(), $ref, 1))->toThrow(RegistrarException::class, 'restore it')
        ->and($driver->info(ncConfigured(), $ref)->status)->toBe('expired');

    $restored = $driver->restore(ncConfigured(), $ref, 1);

    expect($restored->status)->toBe('completed')
        ->and($restored->expiryDate->toDateString())->toBe(CarbonImmutable::now('UTC')->addYear()->toDateString())
        ->and($driver->info(ncConfigured(), $ref)->status)->toBe('active');
});

test('a transfer with the code waits on the other registrar; transfer sync completes it into the account, and a cancel fails it', function () {
    $domain = ncDomain('taken-elsewhere', type: 'transfer', eppCode: NamecheapSimulator::FOREIGN_EPP_CODE);

    app(SubmitToRegistrar::class)->handle($domain);
    app(SyncDomain::class)->handle($domain->fresh());

    expect($domain->fresh()->status)->toBe(DomainStatus::PendingTransfer)
        ->and($domain->fresh()->registrar_meta['remote']['transfer_id'])->toBeInt();

    $this->scenario->simulator()->actions()['settle_transfer'](['domain' => NamecheapSimulator::FOREIGN_DOMAIN, 'outcome' => 'complete'], $this->scenario);
    app(SyncDomain::class)->handle($domain->fresh());

    expect($domain->fresh()->status)->toBe(DomainStatus::Active)
        ->and($domain->fresh()->expiry_date)->not->toBeNull()
        ->and($this->scenario->log()->count('domains_transfer_create'))->toBe(1);

    $this->scenario->simulator()->actions()['add_foreign_domain'](['domain' => 'second-elsewhere.com', 'epp_code' => 'p@ss/word=1'], $this->scenario);
    $second = ncDomain('second-elsewhere', type: 'transfer', eppCode: 'p@ss/word=1', client: $domain->client);
    app(SubmitToRegistrar::class)->handle($second);
    $this->scenario->simulator()->actions()['settle_transfer'](['domain' => 'second-elsewhere.com', 'outcome' => 'cancel'], $this->scenario);
    app(SyncDomain::class)->handle($second->fresh());

    expect($second->fresh()->status)->not->toBe(DomainStatus::Active)
        ->and($this->scenario->state()->get('config', 'balance'))->toBe(NamecheapSimulator::BALANCE - 1_098);
});

test('a wrong transfer code is refused by Namecheap and stays with the person who typed it', function () {
    $domain = ncDomain('taken-elsewhere', type: 'transfer', eppCode: 'not-the-code');

    app(SubmitToRegistrar::class)->handle($domain);

    expect($domain->fresh()->last_error)->toContain('2011170')
        ->and($domain->fresh()->last_error)->not->toContain('not-the-code')
        ->and($this->scenario->state()->all('transfers'))->toBe([]);
});

test('sync finds a domain that left the account only when the registry says so', function () {
    $domain = ncDomain('asha-sim');
    app(SubmitToRegistrar::class)->handle($domain);

    $this->scenario->state()->delete('domains', 'asha-sim.com');
    app(SyncDomain::class)->handle($domain->fresh());
    expect($domain->fresh()->status)->toBe(DomainStatus::Cancelled);

    $other = ncDomain('moved-away', client: $domain->client);
    app(SubmitToRegistrar::class)->handle($other);
    $this->scenario->state()->delete('domains', 'moved-away.com');
    $this->scenario->state()->put('foreign', 'moved-away.com', ['epp' => 'x', 'expires' => time() + 86_400 * 100]);
    app(SyncDomain::class)->handle($other->fresh());

    expect($other->fresh()->status)->toBe(DomainStatus::TransferredAway);
});

test('name servers, the lock, privacy and contacts go through the host and the driver, multi-label extensions included', function () {
    $domain = ncDomain('asha-sim', '.co.uk');
    app(SubmitToRegistrar::class)->handle($domain);
    actingAsStaff(['domains.manage']);

    app(UpdateNameservers::class)->handle($domain->fresh(), ['ns1.new.example.test', 'ns2.new.example.test']);
    $driver = app(NamecheapDriver::class);
    $ref = $domain->fresh()->toRef();
    $privacy = $driver->setIdProtection(ncConfigured(), $ref, true);
    $contacts = $driver->getContacts(ncConfigured(), $ref);
    $info = $driver->info(ncConfigured(), $ref);

    expect(ncRecord('asha-sim.co.uk')['nameservers'])->toBe(['ns1.new.example.test', 'ns2.new.example.test'])
        ->and($privacy->status)->toBe('completed')
        ->and(ncRecord('asha-sim.co.uk')['privacy']['enabled'])->toBeTrue()
        ->and(ncRecord('asha-sim.co.uk')['privacy']['forward'])->toBe('asha@example.test')
        ->and($info->nameservers)->toBe(['ns1.new.example.test', 'ns2.new.example.test'])
        ->and($info->idProtection)->toBeTrue()
        ->and($contacts->role('billing')['phone'])->toBe('+977.15550100');

    $com = ncDomain('lock-sim', client: $domain->client);
    app(SubmitToRegistrar::class)->handle($com);
    app(SetRegistrarLock::class)->handle($com->fresh(), false);

    expect(ncRecord('lock-sim.com')['locked'])->toBeFalse()
        ->and($driver->info(ncConfigured(), $com->fresh()->toRef())->locked)->toBeFalse();

    $changed = new ContactSet(array_map(fn (array $contact): array => [...$contact, 'email' => 'new-owner@example.test', 'first_name' => 'Binod'], app(BuildDomainContacts::class)->contactSet($com->fresh())->toArray()));
    $set = $driver->setContacts(ncConfigured(), $com->fresh()->toRef(), $changed);

    expect($set->meta['transfer_lock_until'])->toBe(CarbonImmutable::today()->addDays(60)->toDateString())
        ->and(ncRecord('lock-sim.com')['contacts']['Registrant']['EmailAddress'])->toBe('new-owner@example.test')
        ->and(ncRecord('lock-sim.com')['lock_until'])->toBeGreaterThan(time() + 59 * 86_400);
});

test('test connection passes, and names the allow-list when the address is not on it', function () {
    $ok = app(RegistrarGateway::class)->testConnection(NamecheapDriver::KEY);
    $this->scenario->simulator()->actions()['ip_allowed'](['allowed' => false], $this->scenario);
    $refused = app(RegistrarGateway::class)->testConnection(NamecheapDriver::KEY);
    $this->scenario->simulator()->actions()['ip_allowed'](['allowed' => true], $this->scenario);
    ncAccount(['client_ip' => '198.51.100.7']);
    $wrongIp = app(RegistrarGateway::class)->testConnection(NamecheapDriver::KEY);

    expect($ok->ok)->toBeTrue()
        ->and($ok->message)->toBe('Connected to the Namecheap sandbox.')
        ->and($refused->ok)->toBeFalse()
        ->and($refused->message)->toContain('allow-list')
        ->and($wrongIp->message)->toContain('allow-list');
});

test('a rate limit is retryable and sends nothing more; the retry registers once', function () {
    $this->scenario->faults()->add('domains_create', Fault::rateLimit(5), nth: 1);
    $domain = ncDomain('asha-sim');

    expect(fn () => app(SubmitToRegistrar::class)->handle($domain))->toThrow(RegistrarException::class, 'limiting requests');
    expect($domain->fresh()->status)->toBe(DomainStatus::Pending)
        ->and($this->scenario->state()->all('domains'))->toBe([]);

    app(SubmitToRegistrar::class)->handle($domain->fresh());

    expect($domain->fresh()->status)->toBe(DomainStatus::Active)
        ->and(count($this->scenario->state()->all('domains')))->toBe(1);
});

test('a chain that has used its time budget is refused before anything is sent', function () {
    $send = fn () => app(NamecheapClient::class)->within(5, fn () => app(NamecheapClient::class)->change(ncConfigured(), 'namecheap.domains.create', ['DomainName' => 'asha-sim.com']));

    expect($send)->toThrow(RegistrarException::class, 'nothing was sent')
        ->and($this->scenario->log()->count('domains_create'))->toBe(0);
});

test('an outbound proxy must be http, https or socks5h and a public address; nothing is sent otherwise', function (string $proxy) {
    ncAccount(['http_proxy' => $proxy]);

    expect(fn () => app(NamecheapDriver::class)->checkAvailability(ncConfigured(), 'asha-sim', ['.com']))->toThrow(RegistrarException::class, 'proxy')
        ->and($this->scenario->log()->entries())->toBe([]);
})->with(['ftp://proxy.example.test:21', 'not a url', 'http://169.254.169.254:80']);

test('a .np name never reaches Namecheap, even when the driver is called directly', function () {
    $ref = new DomainRef(1, 1, 'asha.com.np', 'asha', '.com.np', 'pending', null, NamecheapDriver::KEY);

    expect(fn () => app(NamecheapDriver::class)->register(ncConfigured(), new RegistrationRequest($ref, 1, ['ns1.host.example.test'], new ContactSet)))
        ->toThrow(RegistrarException::class, '.np domains are registered by the client')
        ->and($this->scenario->log()->entries())->toBe([]);
});

test('extension details travel as plain parameters and the registry holds them; undeclared keys are never sent', function () {
    $domain = ncDomain('asha-nexus', '.us');
    $domain->update(['additional_fields' => ['tld_attributes' => ['RegistrantNexus' => 'C11', 'RegistrantPurpose' => 'P3', 'injected' => 'x']]]);

    $result = app(SubmitToRegistrar::class)->handle($domain->fresh());

    expect($result->success)->toBeTrue()
        ->and(ncRecord('asha-nexus.us')['attributes'])->toEqual(['RegistrantNexus' => 'C11', 'RegistrantPurpose' => 'P3']);

    $ca = ncDomain('asha-ca', '.ca', client: $domain->client);
    $ca->update(['additional_fields' => ['tld_attributes' => ['CIRALegalType' => 'CCT', 'CIRAWhoisDisplay' => 'Private', 'CIRALanguage' => 'EN']]]);
    app(SubmitToRegistrar::class)->handle($ca->fresh());

    expect(ncRecord('asha-ca.ca')['attributes'])->toEqual(['CIRALegalType' => 'CCT', 'CIRAWhoisDisplay' => 'Private', 'CIRALanguage' => 'EN']);
});

test('a registration the registry would refuse for missing details stays pending with the reason, and the key is not logged', function () {
    $missing = ncDomain('asha-bare', '.us');
    app(SubmitToRegistrar::class)->handle($missing);

    expect($missing->fresh()->status)->toBe(DomainStatus::Pending)
        ->and($missing->fresh()->last_error)->toContain('extra detail')
        ->and(ncRecord('asha-bare.us'))->toBeNull()
        ->and((string) json_encode(RegistrarCommandLog::query()->get(['request', 'response', 'error'])))->not->toContain(NamecheapSimulator::API_KEY)
        ->and((string) json_encode(ActivityLog::query()->get()->toArray()))->not->toContain(NamecheapSimulator::API_KEY);
});

test('the driver\'s own timeout after a change that ran is unknown, not a failure', function () {
    $this->scenario->faults()->add('domains_dns_setcustom', Fault::commitThenHang(30), nth: 1);
    $domain = ncDomain('asha-sim');
    app(SubmitToRegistrar::class)->handle($domain);

    expect(fn () => app(NamecheapDriver::class)->setNameservers(ncConfigured(), $domain->fresh()->toRef(), ['ns1.late.example.test', 'ns2.late.example.test']))->toThrow(RegistrarTimeout::class)
        ->and(ncRecord('asha-sim.com')['nameservers'])->toBe(['ns1.late.example.test', 'ns2.late.example.test']);
});
