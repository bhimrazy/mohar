<?php

use App\Events\Core\ClientRegistered;
use App\Models\User;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Currency::factory()->create();
});

test('guests are redirected to login', function () {
    $this->get(route('admin.clients.index'))->assertRedirect(route('login'));
});

test('non-staff users cannot access the admin area', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('admin.dashboard'))->assertForbidden();
});

test('staff without permission cannot list clients', function () {
    actingAsStaff(['support.*']);

    $this->get(route('admin.clients.index'))->assertForbidden();
});

test('staff can search clients', function () {
    actingAsStaff(['core.clients.view']);
    Client::factory()->create(['first_name' => 'Ada', 'last_name' => 'Lovelace']);
    Client::factory()->count(3)->create(['first_name' => 'Someone']);

    $this->get(route('admin.clients.index', ['search' => 'Lovelace']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/clients/index')
            ->has('clients.data', 1)
            ->where('clients.data.0.last_name', 'Lovelace'));
});

test('staff can create a client', function () {
    Event::fake([ClientRegistered::class]);
    actingAsStaff(['core.clients.*']);

    $this->post(route('admin.clients.store'), [
        'first_name' => 'Grace',
        'last_name' => 'Hopper',
        'email' => 'grace@example.com',
        'currency' => 'USD',
        'status' => 'active',
        'tax_exempt' => false,
    ])->assertRedirect();

    $client = Client::query()->where('email', 'grace@example.com')->firstOrFail();
    expect($client->first_name)->toBe('Grace');
    Event::assertDispatched(ClientRegistered::class, fn (ClientRegistered $event) => $event->clientId === $client->id);
    $this->assertDatabaseHas('activity_log', ['client_id' => $client->id, 'description' => 'Client created']);
});

test('client creation validates currency and status', function () {
    actingAsStaff();

    $this->post(route('admin.clients.store'), [
        'first_name' => 'X', 'last_name' => 'Y', 'email' => 'not-an-email', 'currency' => 'ZZZ', 'status' => 'weird',
    ])->assertSessionHasErrors(['email', 'currency', 'status']);
});

test('staff can view and update a client', function () {
    actingAsStaff();
    $client = Client::factory()->create();

    $this->get(route('admin.clients.show', $client))
        ->assertInertia(fn (Assert $page) => $page->component('admin/clients/show')->where('client.id', $client->id));

    $this->put(route('admin.clients.update', $client), [
        ...$client->only(['first_name', 'last_name', 'email', 'currency']),
        'status' => 'inactive',
        'tax_exempt' => true,
    ])->assertRedirect(route('admin.clients.show', $client));

    expect($client->fresh())->status->toBe('inactive')->tax_exempt->toBeTrue();
});
