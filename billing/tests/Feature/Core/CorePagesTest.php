<?php

use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Support\Dashboard\Dashboard;
use Inertia\Testing\AssertableInertia as Assert;

test('the admin dashboard renders permitted widgets, deferred widgets and to-dos', function () {
    Currency::factory()->create();
    Client::factory()->count(2)->create();
    $dashboard = app(Dashboard::class);
    $dashboard->widget('admin', 'test.list', 'list', fn () => ['title' => 'Test list', 'items' => [['title' => 'Row']]], 'core.clients.view', 1, 'md');
    $dashboard->widget('admin', 'test.hidden', 'list', fn () => ['title' => 'Hidden', 'items' => []], 'billing.invoices.view');
    $dashboard->widget('admin', 'test.chart', 'chart', fn () => ['title' => 'Chart', 'kind' => 'line', 'series' => []], 'core.clients.view', deferred: true);
    $dashboard->todo('admin', 'test.todo', fn () => ['label' => 'Things to check', 'count' => 3, 'tone' => 'warning'], 'core.clients.view');
    $dashboard->todo('admin', 'test.zero', fn () => ['label' => 'Nothing', 'count' => 0]);
    actingAsStaff(['core.clients.view']);

    $this->get(route('admin.dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/dashboard')
            ->where('widgets', fn ($widgets) => collect($widgets)->pluck('key')->contains('test.list')
                && collect($widgets)->firstWhere('key', 'core.clients')['value'] === 2
                && ! collect($widgets)->pluck('key')->contains('test.hidden')
                && ! collect($widgets)->pluck('key')->contains('test.chart'))
            ->where('todos', [['key' => 'test.todo', 'label' => 'Things to check', 'count' => 3, 'tone' => 'warning']])
            ->where('automation', null)
            ->missing('deferredWidgets')
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->where('deferredWidgets', fn ($widgets) => collect($widgets)->pluck('key')->contains('test.chart')
                    && collect($widgets)->pluck('key')->contains('core.clients.signups'))));
});

test('dashboard widgets and to-dos are hidden without permission', function () {
    actingAsStaff(['support.*']);

    $this->get(route('admin.dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('widgets', [])->where('todos', []));
});

test('the client profile shows activity only to staff with the activity-log permission, without IPs', function () {
    Currency::factory()->create();
    $client = Client::factory()->create();
    ActivityLog::query()->create(['description' => 'Something happened', 'client_id' => $client->id, 'ip_address' => '203.0.113.5']);

    actingAsStaff(['core.clients.view']);
    $this->get(route('admin.clients.show', $client))->assertInertia(fn (Assert $page) => $page->where('activity', null));

    actingAsStaff(['core.clients.view', 'core.activity_log.view']);
    $this->get(route('admin.clients.show', $client))->assertInertia(fn (Assert $page) => $page
        ->where('activity.0.description', 'Something happened')
        ->missing('activity.0.ip_address'));
});

test('creating and editing clients need separate permissions', function () {
    Currency::factory()->create();
    $client = Client::factory()->create();

    actingAsStaff(['core.clients.view', 'core.clients.update']);
    $this->get(route('admin.clients.create'))->assertForbidden();
    $this->get(route('admin.clients.edit', $client))->assertOk()->assertInertia(fn (Assert $page) => $page->component('admin/clients/form')->where('client.id', $client->id)->has('currencies')->has('groups'));

    actingAsStaff(['core.clients.view', 'core.clients.create']);
    $this->get(route('admin.clients.index'))->assertInertia(fn (Assert $page) => $page->where('can.create', true));
    $this->get(route('admin.clients.create'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('defaultCountry', 'US'));
    $this->put(route('admin.clients.update', $client), [...$client->only(['first_name', 'last_name', 'email', 'currency']), 'status' => 'active'])->assertForbidden();
});

test('client updates are logged with a field diff', function () {
    Currency::factory()->create();
    actingAsStaff(['core.clients.*']);
    $client = Client::factory()->create(['first_name' => 'Old']);

    $this->put(route('admin.clients.update', $client), [...$client->only(['last_name', 'email', 'currency']), 'first_name' => 'New', 'status' => 'active']);

    $entry = ActivityLog::query()->where('event', 'client.updated')->sole();
    expect($entry->properties['diff']['first_name'])->toBe(['old' => 'Old', 'new' => 'New']);
});

test('the client dashboard shows the account and its cards', function () {
    app(Dashboard::class)->card('client', 'test.unpaid', fn ($user, Client $client) => ['label' => 'Unpaid invoices', 'value' => 1, 'icon' => 'Receipt']);
    [, $client] = actingAsClient();

    $this->get(route('client.dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('client/dashboard')
            ->where('client.id', $client->id)
            ->where('cards.0.label', 'Unpaid invoices'));
});

test('the client account page shows the current account without staff notes', function () {
    [, $client] = actingAsClient(Client::factory()->create(['notes' => 'Secret staff note']));

    $this->get(route('client.account.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('client/account/edit')
            ->where('client.id', $client->id)
            ->missing('client.notes'));
});
