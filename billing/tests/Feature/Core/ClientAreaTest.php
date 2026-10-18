<?php

use App\Actions\Fortify\CreateNewUser;
use App\Models\User;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use Inertia\Testing\AssertableInertia as Assert;

test('dashboard redirects customers to the client area and staff to admin', function () {
    [$user] = actingAsClient();
    $this->get(route('dashboard'))->assertRedirect(route('client.dashboard'));

    $this->actingAs(User::factory()->staff()->create());
    $this->get(route('dashboard'))->assertRedirect(route('admin.dashboard'));
});

test('client dashboard shows the current account and navigation', function () {
    [, $client] = actingAsClient();

    $this->get(route('client.dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('client/dashboard')
            ->where('client.id', $client->id)
            ->where('area', 'client')
            ->has('navigation'));
});

test('a login without a client account cannot use the client area', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('client.dashboard'))->assertForbidden();
});

test('owners can update account details', function () {
    [, $client] = actingAsClient();

    $this->put(route('client.account.update'), [
        'first_name' => 'New',
        'last_name' => 'Name',
        'email' => $client->email,
        'address_1' => '1 Main St',
        'city' => 'Springfield',
        'postcode' => '12345',
        'country' => 'US',
    ])->assertRedirect();

    expect($client->fresh()->first_name)->toBe('New');
});

test('users can switch only between their own accounts', function () {
    [$user, $first] = actingAsClient();
    $second = Client::factory()->create();
    $second->users()->attach($user);
    $foreign = Client::factory()->create();

    $this->post(route('client.account.switch'), ['client_id' => $second->id])->assertRedirect(route('client.dashboard'));
    $this->get(route('client.dashboard'))->assertInertia(fn (Assert $page) => $page->where('client.id', $second->id));

    $this->post(route('client.account.switch'), ['client_id' => $foreign->id])->assertForbidden();
});

test('non-owner users need the profile permission to edit details', function () {
    Currency::factory()->create();
    $client = Client::factory()->create();
    $user = User::factory()->create();
    $client->users()->attach($user, ['is_owner' => false, 'permissions' => json_encode(['billing'])]);
    $this->actingAs($user);

    $this->get(route('client.account.edit'))->assertForbidden();
});

test('registration creates an owned client account', function () {
    Currency::factory()->create();

    $this->post(route('register.store'), [
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $user = User::query()->where('email', 'jane@example.com')->firstOrFail();
    $client = $user->clients()->firstOrFail();
    expect($client)->first_name->toBe('Jane')->last_name->toBe('Doe')
        ->and((bool) $client->pivot->is_owner)->toBeTrue();
});

test('new users and their client accounts store the email lowercase', function () {
    Currency::factory()->create();

    $user = app(CreateNewUser::class)->create([
        'name' => 'Jane Doe',
        'email' => 'Jane.Doe@Example.COM',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    expect($user->email)->toBe('jane.doe@example.com')
        ->and($user->clients()->sole()->email)->toBe('jane.doe@example.com');
});

test('user emails are stored lowercase on every write path', function () {
    $user = User::factory()->create(['email' => 'Mixed.Case@Example.COM']);
    expect($user->fresh()->email)->toBe('mixed.case@example.com');

    $user->update(['email' => '  Another@Example.com ']);
    expect($user->fresh()->email)->toBe('another@example.com');
});
