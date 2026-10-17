<?php

use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Support\Dashboard\Dashboard;
use Inertia\Testing\AssertableInertia as Assert;

test('the admin dashboard shows permitted cards and recent activity', function () {
    Currency::factory()->create();
    Client::factory()->count(2)->create();
    ActivityLog::query()->create(['description' => 'Something happened']);
    actingAsStaff(['core.clients.view', 'core.activity_log.view']);

    $this->get(route('admin.dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/dashboard')
            ->where('cards.0.key', 'core.clients')
            ->where('cards.0.value', 2)
            ->where('activity.0.description', 'Something happened'));
});

test('dashboard cards and activity are hidden without permission', function () {
    actingAsStaff(['support.*']);

    $this->get(route('admin.dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('cards', [])->where('activity', null));
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
