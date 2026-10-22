<?php

use App\Data\Domains\Registrar\RegistrarConfig;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\ClientGroup;
use App\Modules\Core\Models\Currency;
use App\Modules\Domains\Drivers\Registrars\LogRegistrar;
use App\Modules\Domains\Enums\DomainStatus;
use App\Modules\Domains\Models\Domain;
use App\Modules\Domains\Models\RegistrarAccount;
use App\Modules\Domains\Models\Tld;
use App\Modules\Domains\Models\TldPrice;
use App\Support\Domains\RegistrarDriverRegistry;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fakes\Domains\DomainFixtures;

beforeEach(function () {
    config(['domains.lookup_registrar' => 'log', 'domains.lookup_tlds' => ['.com', '.net'], 'domains.suggestion_tlds' => ['.com', '.net']]);
    $this->com = DomainFixtures::com('log', ['is_spotlight' => true]);
    $this->net = Tld::factory()->extension('.net')->priced(register: 1500)->create(['registrar_key' => 'log']);
});

test('the query is normalised before lookup', function () {
    $this->getJson(route('domains.lookup', ['q' => 'HTTPS://WWW.Example.COM/path']))
        ->assertOk()
        ->assertJsonPath('normalised', 'example.com')
        ->assertJsonCount(1, 'results')
        ->assertJsonPath('results.0.domain', 'example.com')
        ->assertJsonPath('results.0.status', 'available')
        ->assertJsonPath('results.0.register.0', ['years' => 1, 'price' => 1200]);
});

test('a bare name checks spotlight and lookup extensions with suggestions', function () {
    $response = $this->getJson(route('domains.lookup', ['q' => 'brand']))->assertOk();

    expect(collect($response->json('results'))->pluck('domain')->all())->toBe(['brand.com', 'brand.net'])
        ->and(collect($response->json('suggestions'))->pluck('domain')->all())->toContain('getbrand.com')
        ->and(collect($response->json('suggestions'))->pluck('domain')->all())->not->toContain('brand.com');
});

test('taken names are offered as transfers and names held here are not', function () {
    Domain::factory()->named('mybrand', $this->com)->status(DomainStatus::Active)->create();

    $this->getJson(route('domains.lookup', ['q' => 'taken-name.com']))
        ->assertJsonPath('results.0.status', 'registered')
        ->assertJsonPath('results.0.transferable', true)
        ->assertJsonPath('results.0.transfer', 1100);
    $this->getJson(route('domains.lookup', ['q' => 'mybrand.com']))
        ->assertJsonPath('results.0.status', 'registered')
        ->assertJsonPath('results.0.in_system', true)
        ->assertJsonPath('results.0.transferable', false);
});

test('unknown extensions are reported unsupported and invalid input is rejected', function () {
    $this->getJson(route('domains.lookup', ['q' => 'example.dev']))->assertJsonPath('results.0.status', 'unsupported');
    $this->getJson(route('domains.lookup', ['q' => 'bad_name!.com']))->assertJsonPath('error', fn ($error) => is_string($error));
    $this->getJson(route('domains.lookup'))->assertUnprocessable();
});

test('prices follow the client currency and group, and missing currencies are not sellable', function () {
    Currency::factory()->euro()->create();
    $group = ClientGroup::query()->create(['name' => 'Partners']);
    TldPrice::query()->create(['tld_id' => $this->com->id, 'currency' => 'USD', 'client_group_id' => $group->id, 'years' => 1, 'register' => 700]);
    [, $client] = actingAsClient(Client::factory()->create(['client_group_id' => $group->id]));

    $this->getJson(route('domains.lookup', ['q' => 'example.com']))->assertJsonPath('results.0.register.0.price', 700);

    $client->update(['currency' => 'EUR', 'client_group_id' => null]);
    $this->getJson(route('domains.lookup', ['q' => 'example.com']))
        ->assertJsonPath('currency', 'EUR')
        ->assertJsonPath('results.0.sellable', false)
        ->assertJsonPath('results.0.register', []);
});

test('guests may choose a currency', function () {
    Currency::factory()->euro()->create();

    $this->getJson(route('domains.lookup', ['q' => 'example.com', 'currency' => 'eur']))->assertJsonPath('currency', 'EUR');
    $this->getJson(route('domains.lookup', ['q' => 'example.com', 'currency' => 'XXX']))->assertJsonPath('currency', 'USD');
});

test('the public search page renders results for guests', function () {
    $this->get(route('domains.search'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('public/domains/search')->where('search', null));

    $this->get(route('domains.search', ['q' => 'Bücher']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('search.normalised', 'xn--bcher-kva')
            ->where('search.results.0.domain_unicode', 'bücher.com'));
});

test('lookups are rate limited per IP', function () {
    config(['domains.lookup_rate_limit' => 2]);

    $this->getJson(route('domains.lookup', ['q' => 'a1']))->assertOk();
    $this->getJson(route('domains.lookup', ['q' => 'a2']))->assertOk();
    $this->getJson(route('domains.lookup', ['q' => 'a3']))->assertTooManyRequests();
});

test('repeated lookups are served from a serialising cache store', function () {
    config(['cache.default' => 'file', 'cache.stores.file.path' => storage_path('framework/testing/cache-'.uniqid())]);

    $first = $this->getJson(route('domains.lookup', ['q' => 'cached']))->assertOk()->json();
    $this->getJson(route('domains.lookup', ['q' => 'cached']))->assertOk()->assertExactJson($first);
    $this->get(route('domains.search', ['q' => 'cached']))->assertOk();
    $this->get(route('domains.search', ['q' => 'cached']))->assertOk();

    expect($first['results'][0]['status'])->toBe('available')
        ->and($first['suggestions'])->not->toBe([]);

    File::deleteDirectory(config('cache.stores.file.path'));
});

test('successful lookups are not written to the command log; failures are', function () {
    $this->getJson(route('domains.lookup', ['q' => 'quiet.com']))->assertOk();
    $this->assertDatabaseCount('registrar_command_log', 0);

    RegistrarAccount::query()->create(['key' => 'log', 'settings' => ['simulate' => 'none']]);
    app(RegistrarDriverRegistry::class)->register('log', fn () => new class extends LogRegistrar
    {
        public function checkAvailability(RegistrarConfig $config, string $sld, array $tlds): array
        {
            throw new RuntimeException('registry offline');
        }
    }, replace: true);

    $this->getJson(route('domains.lookup', ['q' => 'loud.com']))->assertOk()->assertJsonPath('results.0.status', 'unknown');
    $this->assertDatabaseHas('registrar_command_log', ['domain_id' => null, 'command' => 'availability', 'success' => false]);
});

test('a disabled lookup registrar reports availability as unknown', function () {
    RegistrarAccount::query()->create(['key' => 'log', 'is_active' => false]);

    $this->getJson(route('domains.lookup', ['q' => 'example.com']))->assertJsonPath('results.0.status', 'unknown');
});

test('premium names are not sellable while premium sales are off', function () {
    $this->getJson(route('domains.lookup', ['q' => 'premiumname.com']))
        ->assertJsonPath('results.0.is_premium', true)
        ->assertJsonPath('results.0.sellable', false);
});

test('spaces are dropped and IDN suggestions are built from the unicode label', function () {
    $this->getJson(route('domains.lookup', ['q' => 'Hello World.com']))->assertJsonPath('normalised', 'helloworld.com');

    $suggestions = collect($this->getJson(route('domains.lookup', ['q' => '例え']))->json('suggestions'));

    expect($suggestions->pluck('domain_unicode'))->toContain('get例え.com')
        ->and($suggestions->pluck('domain')->filter(fn ($name) => str_contains($name, 'xn--'))->every(fn ($name) => str_starts_with($name, 'xn--')))->toBeTrue();
});
