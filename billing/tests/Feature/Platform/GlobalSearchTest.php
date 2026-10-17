<?php

use App\Models\User;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\Currency;
use App\Support\Search\GlobalSearch;

beforeEach(fn () => Currency::factory()->create());

test('search needs at least two characters', function () {
    actingAsStaff();

    $this->getJson(route('admin.platform.search', ['q' => 'a']))->assertOk()->assertExactJson(['groups' => []]);
});

test('search is admin-only', function () {
    $this->actingAs(User::factory()->create());

    $this->getJson(route('admin.platform.search', ['q' => 'acme']))->assertForbidden();
});

test('search returns grouped results with links', function () {
    actingAsStaff();
    $client = Client::factory()->create(['first_name' => 'Ada', 'last_name' => 'Lovelace', 'company_name' => null, 'email' => 'ada@engine.test']);
    Contact::factory()->create(['client_id' => $client->id, 'first_name' => 'Adam', 'email' => 'adam@engine.test']);

    $response = $this->getJson(route('admin.platform.search', ['q' => 'Ada']))->assertOk();

    $groups = collect($response->json('groups'))->keyBy('group');
    expect($groups['Clients']['results'][0])->toMatchArray(['title' => 'Ada Lovelace', 'href' => route('admin.clients.show', $client)])
        ->and($groups['Contacts']['results'][0]['title'])->toStartWith('Adam');
});

test('groups the staff cannot view are left out', function () {
    app(GlobalSearch::class)->provider('Invoices', fn (string $term) => [['title' => "INV-{$term}", 'href' => '/admin/invoices/1']], 'billing.invoices.view');
    Client::factory()->create(['first_name' => 'Zed']);

    actingAsStaff(['core.clients.view']);
    $groups = collect($this->getJson(route('admin.platform.search', ['q' => 'Zed']))->json('groups'))->pluck('group');
    expect($groups)->toContain('Clients')->not->toContain('Invoices')->not->toContain('Staff');

    actingAsStaff(['billing.invoices.view']);
    $groups = collect($this->getJson(route('admin.platform.search', ['q' => 'Zed']))->json('groups'))->pluck('group');
    expect($groups->all())->toBe(['Invoices']);
});

test('each group returns at most five results', function () {
    app(GlobalSearch::class)->provider('Many', fn () => array_map(fn (int $i) => ['title' => "Item {$i}", 'href' => "/x/{$i}"], range(1, 9)));
    actingAsStaff();

    $groups = collect($this->getJson(route('admin.platform.search', ['q' => 'item']))->json('groups'))->keyBy('group');
    expect($groups['Many']['results'])->toHaveCount(5);
});

test('LIKE wildcards in the search term cannot match everything', function () {
    actingAsStaff();
    Client::factory()->count(3)->create();

    $this->getJson(route('admin.platform.search', ['q' => '%%']))->assertExactJson(['groups' => []]);
    $this->getJson(route('admin.platform.search', ['q' => '__']))->assertExactJson(['groups' => []]);

    $match = Client::factory()->create(['email' => 'first_last@example.test', 'company_name' => null]);
    $results = collect($this->getJson(route('admin.platform.search', ['q' => 'first_l']))->json('groups'))->keyBy('group');
    expect(collect($results['Clients']['results'])->pluck('href')->all())->toBe([route('admin.clients.show', $match)]);
});
