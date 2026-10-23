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
use App\Modules\Domains\Actions\RetrieveEppCode;
use App\Modules\Domains\Actions\SetRegistrarLock;
use App\Modules\Domains\Actions\SubmitToRegistrar;
use App\Modules\Domains\Actions\SyncDomain;
use App\Modules\Domains\Actions\UpdateNameservers;
use App\Modules\Domains\Drivers\Registrars\ResellerClub\ResellerClubClient;
use App\Modules\Domains\Drivers\Registrars\ResellerClub\ResellerClubContacts;
use App\Modules\Domains\Drivers\Registrars\ResellerClub\ResellerClubDriver;
use App\Modules\Domains\Enums\DomainStatus;
use App\Modules\Domains\Models\Domain;
use App\Modules\Domains\Models\RegistrarAccount;
use App\Modules\Domains\Models\RegistrarCommandLog;
use App\Modules\Domains\Models\Tld;
use App\Modules\Domains\Services\RegistrarGateway;
use App\Modules\Domains\Services\RegistrarManager;
use App\Support\Domains\RegistrarDriverRegistry;
use App\Support\Domains\RegistrarException;
use App\Support\Integrations\Endpoints;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mohar\Simulators\Kernel\Fault;
use Mohar\Simulators\Kernel\Processes;
use Mohar\Simulators\Providers\ResellerClub\ResellerClubApi;
use Mohar\Simulators\Providers\ResellerClub\ResellerClubSimulator;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * The ResellerClub driver, unchanged, against the resellerclub simulator over TLS, pinned by the outbound guard
 * (ADR 0016 §3), through the Domains host: submit, renew, sync, name servers, lock, EPP code, test connection. No
 * Http::fake. Each scenario the driver must survive: success, unavailable, premium, insufficient funds, an async
 * registration, a transfer waiting for its code, a timeout after commit, a rate limit, an unknown actionstatus.
 */
beforeEach(function () {
    $this->scenario = Processes::start(ResellerClubSimulator::class);
    config([
        'integrations.outbound.allow_loopback' => true,
        'integrations.endpoints.overrides' => $this->scenario->simulator()->appConfiguration($this->scenario)['env']['INTEGRATION_ENDPOINTS'],
        'integrations.endpoints.ca_bundle' => $this->scenario->ca()->bundlePath(),
    ]);
    app()->forgetInstance(Endpoints::class);
    // A short client timeout, so a hung answer is given up on quickly; the simulator holds it far longer.
    app()->instance(ResellerClubClient::class, new ResellerClubClient(3));
    rcAccount();
});

afterEach(function () {
    Processes::stop($this->scenario);
});

/**
 * @param  array<string, mixed>  $settings
 */
function rcAccount(array $settings = []): void
{
    RegistrarAccount::query()->updateOrCreate(['key' => ResellerClubDriver::KEY], ['is_active' => true, 'test_mode' => true, 'settings' => [
        'demo_reseller_id' => ResellerClubSimulator::RESELLER_ID, 'demo_api_key' => ResellerClubSimulator::API_KEY, 'customer_mode' => 'per_client', ...$settings,
    ]]);
}

/** A pending domain of a Kathmandu client, with its contacts, sold through ResellerClub. */
function rcDomain(string $sld, string $extension = '.com', string $type = 'register', ?Client $client = null, ?string $eppCode = null): Domain
{
    $tld = Tld::query()->where('extension', $extension)->first() ?? Tld::factory()->extension($extension)->priced('NPR', 150_000, 140_000, 160_000)->create(['registrar_key' => ResellerClubDriver::KEY]);
    $client ??= Client::factory()->create(['company_name' => null, 'first_name' => 'Asha', 'last_name' => 'Shrestha', 'email' => 'asha@example.test', 'phone' => '01-5550100', 'address_1' => '1 Durbar Marg', 'city' => 'Kathmandu', 'state' => 'Bagmati', 'postcode' => '44600', 'country' => 'NP']);
    $domain = Domain::factory()->pending($type)->named($sld, $tld)->create(['client_id' => $client->id, 'registrar_key' => ResellerClubDriver::KEY, 'currency' => 'NPR', 'epp_code' => $eppCode, 'nameservers' => ['ns1.host.example.test', 'ns2.host.example.test']]);
    app(BuildDomainContacts::class)->forNewDomain($domain, $client);

    return $domain->fresh();
}

/** @return array<string, mixed>|null */
function rcOrderOf(string $name): ?array
{
    return (new ResellerClubApi(test()->scenario))->orderByName($name);
}

test('a registration completes with the registry expiry and order id; the next domain reuses the customer and contacts', function () {
    $first = rcDomain('asha-sim');
    $result = app(SubmitToRegistrar::class)->handle($first);
    $order = rcOrderOf('asha-sim.com');
    $first->refresh();

    expect($result->success)->toBeTrue()
        ->and($first->status)->toBe(DomainStatus::Active)
        ->and($first->registrar_meta['remote']['order_id'])->toBe((int) $order['orderid'])
        ->and($first->expiry_date->toDateString())->toBe(CarbonImmutable::createFromTimestamp((int) $order['endtime'])->toDateString())
        ->and($order['ns'])->toBe(['ns1.host.example.test', 'ns2.host.example.test'])
        ->and($this->scenario->state()->all('customers'))->toHaveCount(1)
        ->and($this->scenario->state()->all('contacts'))->toHaveCount(1)
        ->and(head($this->scenario->state()->all('contacts')))->toMatchArray(['telnocc' => '977', 'telno' => '15550100', 'country' => 'NP', 'company' => 'N A', 'name' => 'Asha Shrestha']);

    app(SubmitToRegistrar::class)->handle(rcDomain('asha-sim', '.net', client: $first->client));

    expect($this->scenario->log()->count('customer_signup'))->toBe(1)
        ->and($this->scenario->log()->count('contact_add'))->toBe(1)
        ->and(rcOrderOf('asha-sim.net')['customerid'])->toBe($order['customerid'])
        ->and((string) json_encode(RegistrarCommandLog::query()->get(['request', 'response', 'error'])))->not->toContain(ResellerClubSimulator::API_KEY);
});

test('one default customer holds every domain when the account is set up that way', function () {
    $customer = (string) app(ResellerClubClient::class)->post(app(RegistrarManager::class)->config(ResellerClubDriver::KEY), 'customers/v2/signup.json', [
        'username' => 'host@example.test', 'passwd' => 'HostPass2026', 'name' => 'Host Admin', 'company' => 'Example Hosting', 'address-line-1' => '1 Main Road',
        'city' => 'Lalitpur', 'state' => 'Bagmati', 'country' => 'NP', 'zipcode' => '44700', 'phone-cc' => '977', 'phone' => '15550199', 'lang-pref' => 'en',
    ]);
    rcAccount(['customer_mode' => 'default_customer', 'default_customer_id' => $customer]);

    app(SubmitToRegistrar::class)->handle(rcDomain('asha-sim'));

    expect(rcOrderOf('asha-sim.com')['customerid'])->toBe($customer)
        ->and($this->scenario->log()->count('customer_signup'))->toBe(1);
});

test('a name held elsewhere, a premium name and an empty balance are refused; the domain stays pending with the reason', function () {
    $taken = rcDomain('taken-elsewhere');
    $premium = rcDomain('premium-gold', client: $taken->client);
    app(SubmitToRegistrar::class)->handle($taken);
    app(SubmitToRegistrar::class)->handle($premium);
    $this->scenario->simulator()->actions()['set_balance'](['cents' => 100], $this->scenario);
    $broke = rcDomain('asha-sim', client: $taken->client);
    app(SubmitToRegistrar::class)->handle($broke);

    $config = app(RegistrarManager::class)->config(ResellerClubDriver::KEY);
    $availability = [...app(ResellerClubDriver::class)->checkAvailability($config, 'premium-gold', ['.com']), ...app(ResellerClubDriver::class)->checkAvailability($config, 'asha-free', ['.net', '.in'])];

    expect($taken->fresh()->status)->toBe(DomainStatus::Pending)
        ->and($taken->fresh()->last_error)->toContain('ResellerClub refused the request')
        ->and($premium->fresh()->status)->toBe(DomainStatus::Pending)
        ->and($broke->fresh()->last_error)->toContain('insufficient funds')
        ->and($this->scenario->state()->all('orders'))->toBe([])
        ->and($availability[0]->isPremium)->toBeTrue()
        ->and($availability[0]->status)->toBe('available')
        ->and([$availability[1]->domain, $availability[1]->status, $availability[1]->isPremium])->toBe(['asha-free.net', 'available', false])
        ->and($availability[2]->domain)->toBe('asha-free.in');
});

test('an async registration waits as Pending registration, then sync makes it active', function () {
    $domain = rcDomain('slow-asha');

    app(SubmitToRegistrar::class)->handle($domain);
    expect($domain->fresh()->status)->toBe(DomainStatus::PendingRegistration);

    $this->scenario->simulator()->actions()['settle_order'](['domain' => 'slow-asha.com'], $this->scenario);
    $sync = app(SyncDomain::class)->handle($domain->fresh());

    expect($sync['error'])->toBeNull()
        ->and($domain->fresh()->status)->toBe(DomainStatus::Active)
        ->and($domain->fresh()->expiry_date)->not->toBeNull()
        ->and($this->scenario->log()->count('register'))->toBe(1);
});

test('a registration whose answer is lost waits for review, and the next sync finds the order without sending it again', function () {
    $this->scenario->faults()->add('register', Fault::commitThenHang(30), nth: 1);
    $domain = rcDomain('asha-sim');

    app(SubmitToRegistrar::class)->handle($domain);
    expect($domain->fresh()->needs_review)->toBeTrue()
        ->and($domain->fresh()->status)->toBe(DomainStatus::PendingRegistration);

    $this->travel(SubmitToRegistrar::IN_FLIGHT_MINUTES + 1)->minutes();
    app(SubmitToRegistrar::class)->handle($domain->fresh());
    $sync = app(SyncDomain::class)->handle($domain->fresh());

    expect($sync['error'])->toBeNull()
        ->and($domain->fresh()->status)->toBe(DomainStatus::Active)
        ->and($domain->fresh()->needs_review)->toBeFalse()
        ->and($domain->fresh()->registrar_meta['remote']['order_id'])->toBe((int) rcOrderOf('asha-sim.com')['orderid'])
        ->and($domain->fresh()->expiry_date->toDateString())->toBe(CarbonImmutable::createFromTimestamp((int) rcOrderOf('asha-sim.com')['endtime'])->toDateString())
        ->and($this->scenario->log()->count('register'))->toBe(1);
});

test('a register retried by hand after a lost answer never adopts the order unless the host holds its id', function () {
    $this->scenario->faults()->add('register', Fault::commitThenHang(30), nth: 1);
    $domain = rcDomain('asha-sim');
    app(SubmitToRegistrar::class)->handle($domain);
    $driver = app(RegistrarDriverRegistry::class)->get(ResellerClubDriver::KEY);
    $config = app(RegistrarManager::class)->config(ResellerClubDriver::KEY);
    $request = fn (DomainRef $ref) => new RegistrationRequest($ref, 1, ['ns1.host.example.test', 'ns2.host.example.test'], app(BuildDomainContacts::class)->contactSet($domain));
    $ref = $domain->fresh()->toRef();

    expect(fn () => $driver->register($config, $request($ref)))->toThrow(RegistrarException::class, 'another order');

    $order = (int) rcOrderOf('asha-sim.com')['orderid'];
    $held = $driver->register($config, $request(new DomainRef(...[...get_object_vars($ref), 'remote' => ['order_id' => $order]])));

    expect($held->status)->toBe('completed')
        ->and($held->remote['order_id'])->toBe($order)
        ->and($this->scenario->log()->count('register'))->toBe(1);
});

test('a transfer never adopts an order of the name that the host does not hold, whatever code is typed', function () {
    $customer = rcDefaultCustomer();
    rcAccount(['customer_mode' => 'default_customer', 'default_customer_id' => $customer]);
    $victimDomain = rcDomain('victim-sim');
    app(SubmitToRegistrar::class)->handle($victimDomain);
    $victim = rcOrderOf('victim-sim.com');
    $victimDomain->fresh()->forceFill(['status' => DomainStatus::Cancelled])->save();
    $config = app(RegistrarManager::class)->config(ResellerClubDriver::KEY);
    $attacker = rcDomain('victim-sim', type: 'transfer', eppCode: 'not-the-code');

    $request = new RegistrationRequest($attacker->fresh()->toRef(), 1, [], app(BuildDomainContacts::class)->contactSet($attacker), eppCode: 'not-the-code');

    expect(fn () => app(ResellerClubDriver::class)->transfer($config, $request))->toThrow(RegistrarException::class, 'another order')
        ->and($this->scenario->log()->count('transfer'))->toBe(0)
        ->and($this->scenario->log()->count('submit_auth_code'))->toBe(0)
        ->and(app(ResellerClubDriver::class)->transferSync($config, $attacker->fresh()->toRef())->completed)->toBeFalse()
        ->and(rcOrderOf('victim-sim.com')['orderid'])->toBe($victim['orderid']);
});

test('findSubmission adopts an order of our customer created since the submission, and reports an older one as a conflict', function () {
    $customer = rcDefaultCustomer();
    rcAccount(['customer_mode' => 'default_customer', 'default_customer_id' => $customer]);
    $driver = app(ResellerClubDriver::class);
    $config = app(RegistrarManager::class)->config(ResellerClubDriver::KEY);
    $this->scenario->faults()->add('register', Fault::commitThenHang(30), nth: 1);
    $domain = rcDomain('asha-sim');
    $started = now()->toImmutable()->subSeconds(5);
    app(SubmitToRegistrar::class)->handle($domain);
    $ref = $domain->fresh()->toRef();

    $mine = $driver->findSubmission($config, $ref, 'register', $started);
    $later = $driver->findSubmission($config, $ref, 'register', now()->toImmutable()->addHours(2));

    expect($mine->status)->toBe(ReconcileStatus::Executed)
        ->and($mine->remote['order_id'])->toBe((int) rcOrderOf('asha-sim.com')['orderid'])
        ->and($later->status)->toBe(ReconcileStatus::Conflict);
});

test('per-client mode names the customer from our client id, never from the typed email, and adopts only that exact username', function () {
    $domain = rcDomain('asha-sim');
    $username = ResellerClubContacts::customerUsername($domain->client_id);
    $config = app(RegistrarManager::class)->config(ResellerClubDriver::KEY);
    // An earlier signup whose answer was lost: the customer exists, but no ref is remembered.
    $existing = (string) app(ResellerClubClient::class)->post($config, 'customers/v2/signup.json', [
        'username' => $username, 'passwd' => 'HostPass2026', 'name' => 'Asha Shrestha', 'company' => 'N A', 'address-line-1' => '1 Main Road',
        'city' => 'Lalitpur', 'state' => 'Bagmati', 'country' => 'NP', 'zipcode' => '44700', 'phone-cc' => '977', 'phone' => '15550199', 'lang-pref' => 'en',
    ]);

    app(SubmitToRegistrar::class)->handle($domain);

    expect($username)->toBe('client-'.$domain->client_id.'@'.parse_url((string) config('app.url'), PHP_URL_HOST).(str_contains((string) parse_url((string) config('app.url'), PHP_URL_HOST), '.') ? '' : '.invalid'))
        ->and(rcOrderOf('asha-sim.com')['customerid'])->toBe($existing)
        ->and($this->scenario->state()->all('customers'))->toHaveCount(1);
});

test('sync leaves a registration that never reached ResellerClub for staff instead of cancelling it', function () {
    $domain = rcDomain('never-sent');
    $domain->forceFill(['status' => DomainStatus::PendingRegistration, 'needs_review' => true])->save();

    app(SyncDomain::class)->handle($domain->fresh());

    expect($domain->fresh()->status)->toBe(DomainStatus::PendingRegistration);
});

test('sync cancels an active domain whose recorded order is gone only when the registry says the name is free', function () {
    $domain = rcDomain('asha-sim');
    $domain->forceFill(['status' => DomainStatus::Active, 'expiry_date' => now()->addMonths(6)->toDateString(), 'registrar_meta' => ['remote' => ['order_id' => 99]]])->save();

    app(SyncDomain::class)->handle($domain->fresh());

    expect($domain->fresh()->status)->toBe(DomainStatus::Cancelled);
});

test('sync of an active domain with no recorded order id changes nothing and says no order is linked', function () {
    $domain = rcDomain('asha-sim');
    $domain->forceFill(['status' => DomainStatus::Active, 'expiry_date' => now()->addMonths(6)->toDateString()])->save();

    $sync = app(SyncDomain::class)->handle($domain->fresh());

    expect($sync['error'])->toContain('No ResellerClub order is linked')
        ->and($domain->fresh()->status)->toBe(DomainStatus::Active);
});

test('the order is found past the first search page of a busy account', function () {
    $domain = rcDomain('asha-sim');
    app(SubmitToRegistrar::class)->handle($domain);
    $live = rcOrderOf('asha-sim.com');

    // 60 older, deleted orders of the same name for the same customer sort before the live one.
    for ($i = 1; $i <= 60; $i++) {
        $this->scenario->state()->put('orders', (string) (70_000_000 + $i), [...$live, 'orderid' => (string) (70_000_000 + $i), 'currentstatus' => 'Deleted']);
    }

    $info = app(ResellerClubDriver::class)->info(app(RegistrarManager::class)->config(ResellerClubDriver::KEY), $domain->fresh()->toRef());

    expect($info->status)->toBe('active')
        ->and($info->remote['order_id'])->toBe((int) $live['orderid']);
});

test('a transfer retried after its code was already sent says so instead of failing', function () {
    $domain = rcDomain('taken-elsewhere', type: 'transfer', eppCode: ResellerClubSimulator::FOREIGN_AUTH_CODE);
    app(SubmitToRegistrar::class)->handle($domain);
    $config = app(RegistrarManager::class)->config(ResellerClubDriver::KEY);
    $request = new RegistrationRequest($domain->fresh()->toRef(), 1, [], app(BuildDomainContacts::class)->contactSet($domain), eppCode: ResellerClubSimulator::FOREIGN_AUTH_CODE);

    $again = app(ResellerClubDriver::class)->transfer($config, $request);

    expect($again->status)->toBe('pending')
        ->and($again->message)->toContain('already sent')
        ->and($this->scenario->log()->count('transfer'))->toBe(1);
});

test('a chain that has used its time budget is refused before anything is sent', function () {
    $domain = rcDomain('asha-sim');
    $config = app(RegistrarManager::class)->config(ResellerClubDriver::KEY);

    $send = fn () => app(ResellerClubClient::class)->within(5, fn () => app(ResellerClubClient::class)->post($config, 'domains/register.json', ['domain-name' => 'asha-sim.com']));

    expect($send)->toThrow(RegistrarException::class, 'nothing was sent')
        ->and($this->scenario->log()->count('register'))->toBe(0);
});

test('an outbound proxy must be http, https or socks5h and a public address; nothing is sent otherwise', function (string $proxy) {
    rcAccount(['http_proxy' => $proxy]);
    $config = app(RegistrarManager::class)->config(ResellerClubDriver::KEY);

    expect(fn () => app(ResellerClubDriver::class)->checkAvailability($config, 'asha-sim', ['.com']))->toThrow(RegistrarException::class, 'proxy')
        ->and($this->scenario->log()->entries())->toBe([]);
})->with(['ftp://proxy.example.test:21', 'not a url', 'http://169.254.169.254:80']);

test('a rate limit is retryable and sends nothing; the retry registers once', function () {
    $this->scenario->faults()->add('register', Fault::rateLimit(5), nth: 1);
    $domain = rcDomain('asha-sim');

    expect(fn () => app(SubmitToRegistrar::class)->handle($domain))->toThrow(RegistrarException::class, 'limiting requests');
    expect($domain->fresh()->status)->toBe(DomainStatus::Pending)
        ->and($this->scenario->state()->all('orders'))->toBe([]);

    app(SubmitToRegistrar::class)->handle($domain->fresh());

    expect($domain->fresh()->status)->toBe(DomainStatus::Active)
        ->and($this->scenario->log()->count('register'))->toBe(2)
        ->and(count($this->scenario->state()->all('orders')))->toBe(1);
});

test('an unknown actionstatus is not taken as success: the order is read again', function () {
    $this->scenario->simulator()->actions()['next_actionstatus'](['value' => 'InvoicePaid'], $this->scenario);
    $domain = rcDomain('asha-sim');

    app(SubmitToRegistrar::class)->handle($domain);

    expect($domain->fresh()->status)->toBe(DomainStatus::Active)
        ->and($this->scenario->log()->count('details_by_name'))->toBeGreaterThanOrEqual(1);

    $this->scenario->simulator()->actions()['next_actionstatus'](['value' => 'Unrecognised'], $this->scenario);
    $config = app(RegistrarManager::class)->config(ResellerClubDriver::KEY);
    $renewal = app(ResellerClubDriver::class)->renew($config, $domain->fresh()->toRef(), 1);

    expect($renewal->status)->toBe('completed')
        ->and($renewal->expiryDate->toDateString())->toBe(CarbonImmutable::createFromTimestamp((int) rcOrderOf('asha-sim.com')['endtime'])->toDateString());
});

test('a transfer without its code waits; once the code is given and the registry releases it, transfer sync completes it', function () {
    $domain = rcDomain('taken-elsewhere', type: 'transfer');

    app(SubmitToRegistrar::class)->handle($domain);
    $order = rcOrderOf(ResellerClubSimulator::FOREIGN_DOMAIN);
    app(SyncDomain::class)->handle($domain->fresh());

    expect($domain->fresh()->status)->toBe(DomainStatus::PendingTransfer)
        ->and($order['transfer_stage'])->toBe('awaiting_auth_code');

    $config = app(RegistrarManager::class)->config(ResellerClubDriver::KEY);
    $again = app(ResellerClubDriver::class)->transfer($config, new RegistrationRequest($domain->fresh()->toRef(), 1, [], app(BuildDomainContacts::class)->contactSet($domain), eppCode: ResellerClubSimulator::FOREIGN_AUTH_CODE));
    $this->scenario->simulator()->actions()['settle_order'](['domain' => ResellerClubSimulator::FOREIGN_DOMAIN], $this->scenario);
    app(SyncDomain::class)->handle($domain->fresh());

    expect($again->status)->toBe('pending')
        ->and($this->scenario->log()->count('transfer'))->toBe(1)
        ->and($this->scenario->log()->count('submit_auth_code'))->toBe(1)
        ->and($domain->fresh()->status)->toBe(DomainStatus::Active)
        ->and($domain->fresh()->expiry_date)->not->toBeNull();
});

test('renewal names the registry expiry and is applied once; a lost renewal is reconciled from the new expiry', function () {
    $domain = rcDomain('asha-sim');
    app(SubmitToRegistrar::class)->handle($domain);
    $before = (int) rcOrderOf('asha-sim.com')['endtime'];

    app(PerformRenewal::class)->handle($domain->fresh(), 1);
    $after = (int) rcOrderOf('asha-sim.com')['endtime'];

    expect($after)->toBe((int) (new DateTimeImmutable('@'.$before))->modify('+1 year')->format('U'))
        ->and($domain->fresh()->expiry_date->toDateString())->toBe(CarbonImmutable::createFromTimestamp($after)->toDateString());

    $this->scenario->faults()->add('renew', Fault::commitThenHang(30), nth: 2);
    app(PerformRenewal::class)->handle($domain->fresh(), 1);
    expect($domain->fresh()->needs_review)->toBeTrue();

    app(PerformRenewal::class)->handle($domain->fresh(), 1);

    expect($this->scenario->log()->count('renew'))->toBe(2)
        ->and($domain->fresh()->expiry_date->toDateString())->toBe(CarbonImmutable::createFromTimestamp((int) rcOrderOf('asha-sim.com')['endtime'])->toDateString());
});

test('name servers, the lock and the EPP code go through the host; the code is never stored', function () {
    $domain = rcDomain('asha-sim');
    app(SubmitToRegistrar::class)->handle($domain);
    actingAsStaff(['domains.manage']);

    app(UpdateNameservers::class)->handle($domain->fresh(), ['ns1.new.example.test', 'ns2.new.example.test']);
    app(SetRegistrarLock::class)->handle($domain->fresh(), false);
    $code = app(RetrieveEppCode::class)->handle($domain->fresh(), 'staff', auth()->user());

    expect(rcOrderOf('asha-sim.com')['ns'])->toBe(['ns1.new.example.test', 'ns2.new.example.test'])
        ->and(rcOrderOf('asha-sim.com')['transferlock'])->toBeFalse()
        ->and($code->code)->toBe(rcOrderOf('asha-sim.com')['domsecret'])
        ->and((string) json_encode(RegistrarCommandLog::query()->get(['request', 'response', 'error'])))->not->toContain($code->code);
});

test('test connection passes, and names the allow-list when ResellerClub answers Access Denied', function () {
    $ok = app(RegistrarGateway::class)->testConnection(ResellerClubDriver::KEY);
    $this->scenario->simulator()->actions()['ip_allowed'](['allowed' => false], $this->scenario);
    $refused = app(RegistrarGateway::class)->testConnection(ResellerClubDriver::KEY);

    expect($ok->ok)->toBeTrue()
        ->and($ok->message)->toBe('Connected to the ResellerClub demo account.')
        ->and($refused->ok)->toBeFalse()
        ->and($refused->message)->toContain('allow-list');
});

test('a .np name never reaches ResellerClub, even when the driver is called directly', function () {
    $config = new RegistrarConfig(ResellerClubDriver::KEY, ['demo_reseller_id' => ResellerClubSimulator::RESELLER_ID, 'demo_api_key' => ResellerClubSimulator::API_KEY], true, ['demo_api_key']);
    $ref = new DomainRef(1, 1, 'asha.com.np', 'asha', '.com.np', 'pending', null, ResellerClubDriver::KEY);

    expect(fn () => app(ResellerClubDriver::class)->register($config, new RegistrationRequest($ref, 1, ['ns1.host.example.test'], new ContactSet)))
        ->toThrow(RegistrarException::class, '.np domains are registered by the client')
        ->and($this->scenario->log()->entries())->toBe([]);
});

/** A ResellerClub customer to use as the one default customer. */
function rcDefaultCustomer(): string
{
    return (string) app(ResellerClubClient::class)->post(app(RegistrarManager::class)->config(ResellerClubDriver::KEY), 'customers/v2/signup.json', [
        'username' => 'host@example.test', 'passwd' => 'HostPass2026', 'name' => 'Host Admin', 'company' => 'Example Hosting', 'address-line-1' => '1 Main Road',
        'city' => 'Lalitpur', 'state' => 'Bagmati', 'country' => 'NP', 'zipcode' => '44700', 'phone-cc' => '977', 'phone' => '15550199', 'lang-pref' => 'en',
    ]);
}

test('extension details travel as numbered attribute pairs and the order records them; undeclared keys are never sent', function () {
    $domain = rcDomain('asha-nexus', '.us');
    $domain->update(['additional_fields' => ['tld_attributes' => ['purpose' => 'P3', 'category' => 'C11', 'injected' => 'x']]]);

    $result = app(SubmitToRegistrar::class)->handle($domain->fresh());

    expect($result->success)->toBeTrue()
        ->and(rcOrderOf('asha-nexus.us')['attributes'])->toBe(['purpose' => 'P3', 'category' => 'C11']);

    $au = rcDomain('asha-au', '.com.au');
    $au->update(['additional_fields' => ['tld_attributes' => ['id-type' => 'ABN', 'id' => '51 824 753 556', 'policyReason' => '1', 'isAUWarranty' => 'true']]]);
    app(SubmitToRegistrar::class)->handle($au->fresh());

    expect(rcOrderOf('asha-au.com.au')['attributes'])->toBe(['id-type' => 'ABN', 'id' => '51 824 753 556', 'policyReason' => '1', 'isAUWarranty' => 'true']);
});

test('a registration the registry would refuse for missing details stays pending with the reason, and the values are not logged', function () {
    $missing = rcDomain('asha-bare', '.us');
    app(SubmitToRegistrar::class)->handle($missing);

    $leak = 'LEAK-'.str_repeat('9', 70);
    $secret = rcDomain('asha-leak', '.com.au');
    $secret->update(['additional_fields' => ['tld_attributes' => ['id-type' => 'Other', 'id' => $leak, 'policyReason' => '1', 'isAUWarranty' => 'true']]]);
    app(SubmitToRegistrar::class)->handle($secret->fresh());

    expect($missing->fresh()->status)->toBe(DomainStatus::Pending)
        ->and($missing->fresh()->last_error)->toContain('extra detail')
        ->and(rcOrderOf('asha-bare.us'))->toBeNull()
        ->and($secret->fresh()->status)->toBe(DomainStatus::Pending)
        ->and($secret->fresh()->last_error)->toContain('extra detail id')->not->toContain('LEAK-')
        ->and((string) json_encode(RegistrarCommandLog::query()->get(['request', 'response', 'error'])))->not->toContain('LEAK-')
        ->and((string) json_encode(ActivityLog::query()->get()->toArray()))->not->toContain('LEAK-');
});
