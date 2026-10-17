<?php

use App\Events\Core\ClientClosed;
use App\Models\User;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\Currency;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => Currency::factory()->create());

test('contacts can be added, edited and deleted on a client', function () {
    actingAsStaff(['core.clients.view', 'core.contacts.manage']);
    $client = Client::factory()->create();

    $this->post(route('admin.platform.clients.contacts.store', $client), [
        'first_name' => 'Sam', 'last_name' => 'Ops', 'email' => 'SAM@example.com', 'email_preferences' => ['invoice', 'support'],
    ])->assertRedirect(route('admin.clients.show', $client));

    $contact = $client->contacts()->sole();
    expect($contact->email)->toBe('sam@example.com')
        ->and($contact->email_preferences)->toBe(['general' => false, 'invoice' => true, 'support' => true, 'product' => false, 'domain' => false]);

    $this->put(route('admin.platform.clients.contacts.update', [$client, $contact]), ['first_name' => 'Sam', 'last_name' => 'Ops', 'email' => 'sam@example.com', 'email_preferences' => []])->assertRedirect();
    expect($contact->fresh()->wants('invoice'))->toBeFalse();

    $this->delete(route('admin.platform.clients.contacts.destroy', [$client, $contact]))->assertRedirect();
    expect(Contact::query()->count())->toBe(0);
});

test('contacts are scoped to their client', function () {
    actingAsStaff();
    $client = Client::factory()->create();
    $foreign = Contact::factory()->create();

    $this->delete(route('admin.platform.clients.contacts.destroy', [$client, $foreign]))->assertNotFound();
    expect($foreign->fresh())->not->toBeNull();
});

test('contact management requires core.contacts.manage', function () {
    actingAsStaff(['core.clients.*']);
    $client = Client::factory()->create();

    $this->post(route('admin.platform.clients.contacts.store', $client), ['first_name' => 'A', 'last_name' => 'B', 'email' => 'a@example.com', 'email_preferences' => []])->assertForbidden();
});

test('a new email gets a login on the account and a set-password email', function () {
    Notification::fake();
    actingAsStaff(['core.users.manage']);
    $client = Client::factory()->withOwner()->create();

    $this->post(route('admin.platform.clients.users.store', $client), ['email' => 'Helper@Example.com', 'name' => 'Helper', 'permissions' => ['billing', 'support']])
        ->assertRedirect(route('admin.clients.show', $client));

    $helper = User::query()->where('email', 'helper@example.com')->sole();
    $pivot = $client->users()->whereKey($helper->id)->sole()->pivot;
    expect((bool) $pivot->is_owner)->toBeFalse()
        ->and(json_decode($pivot->permissions, true))->toBe(['billing', 'support']);
    Notification::assertSentTo($helper, ResetPassword::class);
});

test('existing logins can be added but staff logins and duplicates cannot', function () {
    actingAsStaff(['core.users.manage']);
    $client = Client::factory()->withOwner()->create();
    $existing = User::factory()->create();
    $staff = User::factory()->staff()->create();

    $this->post(route('admin.platform.clients.users.store', $client), ['email' => $existing->email, 'name' => 'x', 'permissions' => []])->assertSessionHasNoErrors();
    $this->post(route('admin.platform.clients.users.store', $client), ['email' => $existing->email, 'name' => 'x', 'permissions' => []])->assertSessionHasErrors('email');
    $this->post(route('admin.platform.clients.users.store', $client), ['email' => $staff->email, 'name' => 'x', 'permissions' => []])->assertSessionHasErrors('email');
    $this->post(route('admin.platform.clients.users.store', $client), ['email' => 'n@example.com', 'name' => 'x', 'permissions' => ['root']])->assertSessionHasErrors('permissions.0');

    expect($client->users()->count())->toBe(2);
});

test('permissions can be changed, ownership transferred, and the owner cannot be removed', function () {
    actingAsStaff(['core.users.manage']);
    $client = Client::factory()->withOwner()->create();
    $owner = $client->users()->sole();
    $helper = User::factory()->create();
    $client->users()->attach($helper, ['is_owner' => false, 'permissions' => json_encode([])]);

    $this->put(route('admin.platform.clients.users.update', [$client, $helper]), ['permissions' => ['domains']])->assertRedirect();
    expect(json_decode($client->users()->whereKey($helper->id)->sole()->pivot->permissions, true))->toBe(['domains']);

    $this->delete(route('admin.platform.clients.users.destroy', [$client, $owner]))->assertSessionHasErrors('user');

    $this->post(route('admin.platform.clients.users.transfer', [$client, $helper]))->assertRedirect();
    expect((bool) $client->users()->whereKey($helper->id)->sole()->pivot->is_owner)->toBeTrue()
        ->and((bool) $client->users()->whereKey($owner->id)->sole()->pivot->is_owner)->toBeFalse();

    $this->delete(route('admin.platform.clients.users.destroy', [$client, $owner]))->assertRedirect();
    expect($client->users()->pluck('users.id')->all())->toBe([$helper->id]);
});

test('logins of another account cannot be managed through this client', function () {
    actingAsStaff(['core.users.manage']);
    $client = Client::factory()->withOwner()->create();
    $other = Client::factory()->withOwner()->create();
    $foreignOwner = $other->users()->sole();

    $this->post(route('admin.platform.clients.users.transfer', [$client, $foreignOwner]))->assertNotFound();
    $this->delete(route('admin.platform.clients.users.destroy', [$client, $foreignOwner]))->assertNotFound();
});

test('closing a client sets closed status and emits ClientClosed; reopening restores active', function () {
    Event::fake([ClientClosed::class]);
    actingAsStaff(['core.clients.view', 'core.clients.close']);
    $client = Client::factory()->create();

    $this->post(route('admin.platform.clients.close', $client))->assertRedirect(route('admin.clients.show', $client));

    expect($client->fresh())->status->toBe('closed')->closed_at->not->toBeNull();
    Event::assertDispatched(ClientClosed::class, fn (ClientClosed $event) => $event->clientId === $client->id);
    $this->assertDatabaseHas('activity_log', ['event' => 'client.closed', 'client_id' => $client->id]);

    $this->post(route('admin.platform.clients.close', $client))->assertSessionHasErrors('client');

    $this->post(route('admin.platform.clients.reopen', $client))->assertRedirect();
    expect($client->fresh())->status->toBe('active')->closed_at->toBeNull();
});

test('closing requires core.clients.close', function () {
    actingAsStaff(['core.clients.view', 'core.clients.update']);
    $client = Client::factory()->create();

    $this->post(route('admin.platform.clients.close', $client))->assertForbidden();
});

test('merging clients with different currencies is rejected (422)', function () {
    Currency::factory()->euro()->create();
    actingAsStaff(['core.clients.merge']);
    $source = Client::factory()->create(['currency' => 'USD']);
    $target = Client::factory()->create(['currency' => 'EUR']);

    $this->postJson(route('admin.platform.clients.merge', $source), ['target_id' => $target->id])->assertUnprocessable()->assertJsonValidationErrors('target_id');
    $this->postJson(route('admin.platform.clients.merge', $source), ['target_id' => $source->id])->assertUnprocessable();
});

test('merging moves related records and users and closes the source as a tombstone', function () {
    Schema::create('platform_test_services', function (Blueprint $table) {
        $table->id();
        $table->foreignId('client_id');
        $table->string('domain');
    });
    actingAsStaff(['core.clients.*']);
    $source = Client::factory()->withOwner()->create();
    $target = Client::factory()->withOwner()->create();
    $sourceOwner = $source->users()->sole();
    Contact::factory()->count(2)->create(['client_id' => $source->id]);
    DB::table('platform_test_services')->insert([['client_id' => $source->id, 'domain' => 'a.test'], ['client_id' => $source->id, 'domain' => 'b.test']]);

    $this->getJson(route('admin.platform.clients.merge-preview', [$source, 'target_id' => $target->id]))
        ->assertOk()
        ->assertJsonPath('target.id', $target->id)
        ->assertJsonPath('counts.contacts', 2)
        ->assertJsonPath('counts.platform_test_services', 2);

    $this->post(route('admin.platform.clients.merge', $source), ['target_id' => $target->id])->assertRedirect(route('admin.clients.show', $target));

    expect($target->contacts()->count())->toBe(2)
        ->and(DB::table('platform_test_services')->where('client_id', $target->id)->count())->toBe(2)
        ->and($source->fresh())->status->toBe('closed')->merged_into_client_id->toBe($target->id)
        ->and($source->users()->count())->toBe(0);

    $moved = $target->users()->whereKey($sourceOwner->id)->sole()->pivot;
    expect((bool) $moved->is_owner)->toBeFalse();

    $this->get(route('admin.clients.show', $source))->assertRedirect(route('admin.clients.show', $target));
    $this->post(route('admin.platform.clients.merge', $source), ['target_id' => $target->id])->assertSessionHasErrors('target_id');
});

test('the client profile exposes contacts, logins, links, activity and permissions', function () {
    actingAsStaff(['core.clients.view', 'core.contacts.manage']);
    $client = Client::factory()->withOwner()->create();
    Contact::factory()->create(['client_id' => $client->id]);

    $this->get(route('admin.clients.show', $client))
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/clients/show')
            ->has('contacts', 1)
            ->has('users', 1)
            ->where('users.0.is_owner', true)
            ->where('users.0.permissions', [])
            ->has('links')
            ->has('activity')
            ->where('areas', ['billing', 'services', 'domains', 'support', 'profile', 'contacts'])
            ->where('can.manageContacts', true)
            ->where('can.impersonate', false)
            ->where('can.merge', false));
});
