<?php

use App\Contracts\Domains\DomainNameRules;
use App\Contracts\Domains\Registrar\RegistrarRefs;
use App\Data\Domains\Registrar\RegistrarRef;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Core\Support\NullDomainNameRules;
use App\Modules\Domains\Drivers\Registrars\ResellerClub\ResellerClubDriver;
use App\Modules\Domains\Enums\DomainStatus;
use App\Modules\Domains\Models\Domain;
use App\Modules\Domains\Models\RegistrarAccount;
use App\Modules\Domains\Models\Tld;
use App\Modules\Domains\Services\RegistrarManager;
use App\Support\Clients\ClientMergeRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    Currency::factory()->create();
    config(['domains.lookup_registrar' => 'log']);
});

test('the .np second-level rule has one source: the contract answers for it, and the null default restricts nothing', function () {
    $rules = app(DomainNameRules::class);

    expect($rules->registryLevelError('asha.np'))->toContain('yourname.com.np')
        ->and($rules->registryLevelError('asha.com.np'))->toBeNull()
        ->and($rules->registryLevelError('asha.com'))->toBeNull()
        ->and((new NullDomainNameRules)->registryLevelError('asha.np'))->toBeNull()
        ->and(Tld::registryLevelHint('asha.np'))->toContain('.com.np')->toContain('.org.np')
        ->and(Tld::registryLevelHint('asha.com.np'))->toBeNull();
});

test('domain search shows the TLD\'s own how-to link and falls back to the host-wide one', function () {
    Tld::factory()->extension('.com.np')->priced()->create(['registrar_key' => 'log', 'how_to_url' => 'https://example.com/np-guide']);
    Tld::factory()->extension('.org.np')->priced()->create(['registrar_key' => 'log']);

    $this->getJson(route('domains.lookup', ['q' => 'asha.com.np']))->assertJsonPath('results.0.registration_url', 'https://example.com/np-guide');
    $this->getJson(route('domains.lookup', ['q' => 'asha.org.np']))->assertJsonPath('results.0.registration_url', 'https://register.com.np/how-to-register-np-domain');
});

test('registrar refs follow a merged client; the target keeps its own where both have the same ref', function () {
    $refs = app(RegistrarRefs::class);
    $source = Client::factory()->create();
    $target = Client::factory()->create();
    $other = Client::factory()->create();
    $refs->remember(new RegistrarRef('resellerclub', 'customer', 'client', $source->id, 'src-cust'));
    $refs->remember(new RegistrarRef('resellerclub', 'contact', 'client', $source->id, 'src-a', 'hash-a'));
    $refs->remember(new RegistrarRef('resellerclub', 'contact', 'client', $source->id, 'src-b', 'hash-b'));
    $refs->remember(new RegistrarRef('namecheap', 'customer', 'client', $source->id, 'src-nc'));
    $refs->remember(new RegistrarRef('resellerclub', 'customer', 'client', $target->id, 'tgt-cust'));
    $refs->remember(new RegistrarRef('resellerclub', 'contact', 'client', $target->id, 'tgt-b', 'hash-b'));
    $refs->remember(new RegistrarRef('resellerclub', 'customer', 'client', $other->id, 'other-cust'));

    $preview = collect(app(ClientMergeRegistry::class)->preview($source->id, $target->id))->keyBy('key');
    expect($preview['domains.registrar_refs']['count'])->toBe(4);

    actingAsStaff(['core.clients.view', 'core.clients.merge']);
    confirmPassword();
    $this->post(route('admin.platform.clients.merge', $source), ['target_id' => $target->id, 'confirmation' => 'MERGE'])->assertSessionHasNoErrors()->assertRedirect();

    expect($refs->find('resellerclub', 'customer', 'client', $target->id)?->remoteId)->toBe('tgt-cust')
        ->and($refs->find('resellerclub', 'contact', 'client', $target->id, 'hash-a')?->remoteId)->toBe('src-a')
        ->and($refs->find('resellerclub', 'contact', 'client', $target->id, 'hash-b')?->remoteId)->toBe('tgt-b')
        ->and($refs->find('namecheap', 'customer', 'client', $target->id)?->remoteId)->toBe('src-nc')
        ->and($refs->find('resellerclub', 'customer', 'client', $other->id)?->remoteId)->toBe('other-cust')
        ->and(DB::table('registrar_refs')->where('local_id', $source->id)->count())->toBe(0)
        ->and(DB::table('registrar_refs')->count())->toBe(5);
});

test('a merged client\'s domains move with their refs, and the merge preview counts both', function () {
    $source = Client::factory()->create();
    $target = Client::factory()->create();
    $domain = Domain::factory()->create(['client_id' => $source->id]);
    $trashed = Domain::factory()->create(['client_id' => $source->id]);
    $trashed->delete();
    $kept = Domain::factory()->create(['client_id' => $target->id]);

    $preview = collect(app(ClientMergeRegistry::class)->preview($source->id, $target->id))->keyBy('key');
    expect($preview['domains.domains']['count'])->toBe(2);

    actingAsStaff(['core.clients.view', 'core.clients.merge']);
    confirmPassword();
    $this->post(route('admin.platform.clients.merge', $source), ['target_id' => $target->id, 'confirmation' => 'MERGE'])->assertSessionHasNoErrors();

    expect(Domain::withTrashed()->where('client_id', $target->id)->count())->toBe(3)
        ->and(Domain::withTrashed()->where('client_id', $source->id)->count())->toBe(0)
        ->and($domain->fresh()->client_id)->toBe($target->id);
});

test('a per-client ResellerClub domain still finds its customer after a merge, so linking an order works', function () {
    RegistrarAccount::query()->updateOrCreate(['key' => ResellerClubDriver::KEY], ['is_active' => true, 'test_mode' => true, 'settings' => [
        'demo_reseller_id' => '555555', 'demo_api_key' => 'k', 'customer_mode' => 'per_client',
    ]]);
    $source = Client::factory()->create();
    $target = Client::factory()->create();
    app(RegistrarRefs::class)->remember(new RegistrarRef('resellerclub', 'customer', 'client', $source->id, '14000009'));
    $tld = Tld::factory()->extension('.com')->priced()->create(['registrar_key' => 'resellerclub']);
    $domain = Domain::factory()->named('merged-sim', $tld)->create(['client_id' => $source->id, 'registrar_key' => 'resellerclub', 'status' => DomainStatus::Active]);
    Http::fake(fn () => Http::response(['orderid' => '80000001', 'domainname' => 'merged-sim.com', 'currentstatus' => 'Active', 'resellerid' => '555555', 'customerid' => '14000009', 'endtime' => (string) (time() + 86400 * 200)]));
    $lookup = fn () => app(ResellerClubDriver::class)->lookupOrder(app(RegistrarManager::class)->config('resellerclub'), $domain->fresh()->toRef(), '80000001');

    actingAsStaff(['core.clients.view', 'core.clients.merge']);
    confirmPassword();
    $this->post(route('admin.platform.clients.merge', $source), ['target_id' => $target->id, 'confirmation' => 'MERGE'])->assertSessionHasNoErrors();

    expect($domain->fresh()->client_id)->toBe($target->id)
        ->and($lookup()->remote['order_id'])->toBe(80000001);
});

test('a TLD with no price in the visitor\'s currency gets its own message; .np keeps "not sold here"', function () {
    Tld::factory()->extension('.com')->priced('USD')->create(['registrar_key' => 'log']);
    Tld::factory()->extension('.com.np')->priced('NPR')->create(['registrar_key' => 'log']);
    $errors = fn (string $domain): array => app(App\Modules\Orders\Pricing\CartPricer::class)
        ->price([new App\Modules\Orders\Data\CartItem(key: 'k1', kind: App\Modules\Orders\Enums\CartItemKind::DomainRegister, domainName: $domain, years: 1)], 'NPR')->items[0]->errors;

    expect($errors('asha.com'))->toBe(['.com domains cannot be registered here in NPR.'])
        ->and($errors('asha.com.np')[0])->toStartWith('.com.np domains are not sold here');
});

test('a registrar call reads the registrar account once', function () {
    Tests\Fakes\Domains\FakeRegistrar::install();
    RegistrarAccount::query()->create(['key' => 'fake', 'is_active' => true, 'test_mode' => false, 'settings' => []]);

    DB::enableQueryLog();
    app(App\Modules\Domains\Services\RegistrarGateway::class)->call('fake', 'info', App\Contracts\Domains\Registrar\RegistrarDriver::class, fn () => 'ok');
    $reads = collect(DB::getQueryLog())->filter(fn (array $q): bool => str_contains($q['query'], 'registrar_accounts') && str_starts_with($q['query'], 'select'));

    expect($reads)->toHaveCount(1);
});
