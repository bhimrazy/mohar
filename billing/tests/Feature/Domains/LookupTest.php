<?php

use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\ClientGroup;
use App\Modules\Core\Models\Currency;
use App\Modules\Domains\Enums\DomainStatus;
use App\Modules\Domains\Models\Domain;
use App\Modules\Domains\Models\Tld;
use App\Modules\Domains\Models\TldPrice;
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

test('lookups are cached and logged without a domain', function () {
    $this->getJson(route('domains.lookup', ['q' => 'cached.com']));
    $this->getJson(route('domains.lookup', ['q' => 'cached.com']));

    $this->assertDatabaseCount('registrar_command_log', 2);
    $this->assertDatabaseHas('registrar_command_log', ['domain_id' => null, 'command' => 'availability']);
});
