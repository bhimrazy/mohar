<?php

/*
 * Staff link an existing ResellerClub order to an imported or staff-created domain (#338), against the simulator:
 * the order is read by the id staff typed and must be in our reseller account, for exactly the domain's name, still
 * holding it, and under the customer this host uses; no other domain may hold it. Sync never links by name.
 */

use App\Contracts\Domains\Registrar\RegistrarRefs;
use App\Data\Domains\Registrar\RegistrarRef;
use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Models\Client;
use App\Modules\Domains\Actions\LinkRegistrarOrder;
use App\Modules\Domains\Actions\RetrieveEppCode;
use App\Modules\Domains\Actions\SyncDomain;
use App\Modules\Domains\Drivers\Registrars\ResellerClub\ResellerClubClient;
use App\Modules\Domains\Drivers\Registrars\ResellerClub\ResellerClubDriver;
use App\Modules\Domains\Enums\DomainStatus;
use App\Modules\Domains\Models\Domain;
use App\Modules\Domains\Models\RegistrarAccount;
use App\Modules\Domains\Models\RegistrarCommandLog;
use App\Modules\Domains\Models\Tld;
use App\Modules\Domains\Services\RegistrarManager;
use App\Support\Domains\RegistrarException;
use App\Support\Integrations\Endpoints;
use Carbon\CarbonImmutable;
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
    $this->settings = fn (array $settings) => RegistrarAccount::query()->updateOrCreate(['key' => ResellerClubDriver::KEY], ['is_active' => true, 'test_mode' => true, 'settings' => [
        'demo_reseller_id' => ResellerClubSimulator::RESELLER_ID, 'demo_api_key' => ResellerClubSimulator::API_KEY, ...$settings,
    ]]);
    // The host's default customer at ResellerClub, and a second customer of the same reseller.
    $signup = fn (string $username): string => (string) app(ResellerClubClient::class)->post(app(RegistrarManager::class)->config(ResellerClubDriver::KEY), 'customers/v2/signup.json', [
        'username' => $username, 'passwd' => 'HostPass2026', 'name' => 'Host Admin', 'company' => 'Example Hosting', 'address-line-1' => '1 Main Road',
        'city' => 'Lalitpur', 'state' => 'Bagmati', 'country' => 'NP', 'zipcode' => '44700', 'phone-cc' => '977', 'phone' => '15550199', 'lang-pref' => 'en',
    ]);
    ($this->settings)([]);
    $this->customer = $signup('host@example.test');
    $this->otherCustomer = $signup('someone-else@example.test');
    ($this->settings)(['customer_mode' => 'default_customer', 'default_customer_id' => $this->customer]);
    /** A domain imported from another billing system: Active, with no order id. */
    $this->imported = function (string $name = 'imported-sim.com', array $attributes = []): Domain {
        $tld = Tld::query()->where('extension', '.com')->first() ?? Tld::factory()->extension('.com')->priced('NPR', 150_000, 140_000, 160_000)->create(['registrar_key' => ResellerClubDriver::KEY]);
        $sld = substr($name, 0, -4);

        return Domain::factory()->named($sld, $tld)->create([
            'client_id' => Client::factory()->create()->id, 'registrar_key' => ResellerClubDriver::KEY, 'status' => DomainStatus::Active, 'currency' => 'NPR',
            'expiry_date' => CarbonImmutable::today()->addMonths(2), ...$attributes,
        ])->fresh();
    };
    $this->order = fn (string $name, array $input = []): string => (string) $this->scenario->simulator()->actions()['add_order'](['domain' => $name, 'customer_id' => $this->customer, ...$input], $this->scenario)['order_id'];
    $this->lookups = fn (): int => $this->scenario->log()->count('details_by_id');
});

afterEach(function () {
    Processes::stop($this->scenario);
});

test('an imported domain is unmanaged until staff link its order, then sync and the EPP code work', function () {
    $domain = ($this->imported)();
    $orderId = ($this->order)('imported-sim.com');
    $remote = (new ResellerClubApi($this->scenario))->order($orderId);

    expect(fn () => app(RetrieveEppCode::class)->handle($domain, 'staff', null))->toThrow(RegistrarException::class, 'No ResellerClub order is linked');
    expect(app(SyncDomain::class)->handle($domain)['error'])->toContain('No ResellerClub order is linked');

    $result = app(LinkRegistrarOrder::class)->handle($domain, $orderId, staffId: null);
    $domain->refresh();

    expect($result['linked'])->toBeTrue()
        ->and($result['sync']['error'])->toBeNull()
        ->and($domain->registrar_meta['remote']['order_id'])->toBe((int) $orderId)
        ->and($domain->expiry_date->toDateString())->toBe(CarbonImmutable::createFromTimestamp((int) $remote['endtime'])->toDateString())
        ->and($domain->last_sync_status)->toBe('ok')
        ->and(app(RetrieveEppCode::class)->handle($domain, 'staff', null)->code)->toBe($remote['domsecret'])
        ->and(ActivityLog::query()->where('event', 'domain.order_linked')->where('subject_id', $domain->id)->count())->toBe(1)
        ->and((string) json_encode(RegistrarCommandLog::query()->get(['request', 'response', 'error'])))->not->toContain(ResellerClubSimulator::API_KEY);
});

test('linking again is a no-op: one audit entry, one lookup, and a sync each time', function () {
    $domain = ($this->imported)();
    $orderId = ($this->order)('imported-sim.com');

    $first = app(LinkRegistrarOrder::class)->handle($domain, $orderId);
    $second = app(LinkRegistrarOrder::class)->handle($domain->fresh(), $orderId);

    expect($first['linked'])->toBeTrue()
        ->and($second['linked'])->toBeFalse()
        ->and($second['sync']['error'])->toBeNull()
        ->and(($this->lookups)())->toBe(1)
        ->and(ActivityLog::query()->where('event', 'domain.order_linked')->count())->toBe(1)
        ->and(fn () => app(LinkRegistrarOrder::class)->handle($domain->fresh(), (string) ((int) $orderId + 1)))->toThrow(RegistrarException::class, 'is already linked to order '.$orderId);
});

test('an order for another name is refused, whatever its other details', function () {
    $domain = ($this->imported)();
    $other = ($this->order)('someone-elses.com');

    expect(fn () => app(LinkRegistrarOrder::class)->handle($domain, $other))
        ->toThrow(RegistrarException::class, "Order {$other} is for someone-elses.com, not imported-sim.com. The name must match exactly.");

    expect($domain->fresh()->registrar_meta)->toBeNull()->and(ActivityLog::query()->where('event', 'domain.order_linked')->exists())->toBeFalse();
});

test('an order of the right name under another customer is refused', function () {
    $domain = ($this->imported)();
    $other = ($this->order)('imported-sim.com', ['customer_id' => $this->otherCustomer]);

    expect(fn () => app(LinkRegistrarOrder::class)->handle($domain, $other))
        ->toThrow(RegistrarException::class, 'belongs to a different ResellerClub customer');

    expect($domain->fresh()->registrar_meta)->toBeNull();
});

test('another reseller\'s order, an unknown id and an order that no longer holds the name are refused', function () {
    $domain = ($this->imported)();
    $foreign = ($this->order)('imported-sim.com', ['reseller_id' => '777777']);

    expect(fn () => app(LinkRegistrarOrder::class)->handle($domain, $foreign))->toThrow(RegistrarException::class, "ResellerClub has no order {$foreign} in this reseller account");
    expect(fn () => app(LinkRegistrarOrder::class)->handle($domain, '99999999'))->toThrow(RegistrarException::class, 'ResellerClub has no order 99999999 in this reseller account');

    $gone = ($this->order)('imported-sim.com', ['status' => 'Deleted']);

    expect(fn () => app(LinkRegistrarOrder::class)->handle($domain, $gone))->toThrow(RegistrarException::class, 'is Deleted at ResellerClub and no longer holds the name');
    expect($domain->fresh()->registrar_meta)->toBeNull();
});

test('an order another domain holds is refused before the registrar is asked', function () {
    $orderId = ($this->order)('imported-sim.com');
    $holder = ($this->imported)('imported-sim.com', ['registrar_meta' => ['remote' => ['order_id' => (int) $orderId]], 'status' => DomainStatus::Cancelled]);
    $twin = ($this->imported)('imported-twin.com');

    expect(fn () => app(LinkRegistrarOrder::class)->handle($twin, $orderId))
        ->toThrow(RegistrarException::class, "Order {$orderId} is already linked to {$holder->name}");

    expect(($this->lookups)())->toBe(0)->and($twin->fresh()->registrar_meta)->toBeNull();
});

test('in one-customer-per-client mode the order must be under that client\'s customer, which must be known', function () {
    ($this->settings)(['customer_mode' => 'per_client']);
    $domain = ($this->imported)();
    $orderId = ($this->order)('imported-sim.com');

    expect(fn () => app(LinkRegistrarOrder::class)->handle($domain, $orderId))->toThrow(RegistrarException::class, 'no ResellerClub customer recorded yet');

    app(RegistrarRefs::class)->remember(new RegistrarRef(ResellerClubDriver::KEY, 'customer', 'client', $domain->client_id, $this->otherCustomer));
    expect(fn () => app(LinkRegistrarOrder::class)->handle($domain, $orderId))->toThrow(RegistrarException::class, 'different ResellerClub customer');

    app(RegistrarRefs::class)->remember(new RegistrarRef(ResellerClubDriver::KEY, 'customer', 'client', $domain->client_id, $this->customer));

    expect(app(LinkRegistrarOrder::class)->handle($domain, $orderId)['linked'])->toBeTrue();
});

test('sync never links an order it merely finds by name', function () {
    $domain = ($this->imported)();
    ($this->order)('imported-sim.com');

    $result = app(SyncDomain::class)->handle($domain);

    expect($result['error'])->toContain('No ResellerClub order is linked')
        ->and($domain->fresh()->registrar_meta)->toBeNull()
        ->and($this->scenario->log()->count('search'))->toBe(0);
});
