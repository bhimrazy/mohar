<?php

use App\Events\Core\ClientClosed;
use App\Events\Core\ClientMerged;
use App\Events\Core\ClientReopened;
use App\Events\Core\TwoFactorReset;
use App\Models\User;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\Currency;
use App\Modules\Platform\Notifications\SecurityNotice;
use App\Support\Clients\ClientMergeRegistry;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Currency::factory()->create();
    confirmPassword();
});

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

test('closing a client sets closed status and emits ClientClosed; reopening emits ClientReopened', function () {
    Event::fake([ClientClosed::class, ClientReopened::class]);
    actingAsStaff(['core.clients.view', 'core.clients.close']);
    $client = Client::factory()->create();

    $this->post(route('admin.platform.clients.close', $client))->assertRedirect(route('admin.clients.show', $client));

    expect($client->fresh())->status->toBe('closed')->closed_at->not->toBeNull();
    Event::assertDispatched(ClientClosed::class, fn (ClientClosed $event) => $event->clientId === $client->id);
    $this->assertDatabaseHas('activity_log', ['event' => 'client.closed', 'client_id' => $client->id]);

    $this->post(route('admin.platform.clients.close', $client))->assertSessionHasErrors('client');

    $this->post(route('admin.platform.clients.reopen', $client))->assertRedirect();
    expect($client->fresh())->status->toBe('active')->closed_at->toBeNull();
    Event::assertDispatched(ClientReopened::class, fn (ClientReopened $event) => $event->clientId === $client->id);
});

test('closing requires core.clients.close', function () {
    actingAsStaff(['core.clients.view', 'core.clients.update']);
    $client = Client::factory()->create();

    $this->post(route('admin.platform.clients.close', $client))->assertForbidden();
});

test('a client.* wildcard does not grant close, merge or impersonate', function () {
    actingAsStaff(['core.clients.*']);
    $client = Client::factory()->create();

    $this->post(route('admin.platform.clients.close', $client))->assertForbidden();
    $this->post(route('admin.platform.clients.merge', $client), ['target_id' => $client->id, 'confirmation' => 'MERGE'])->assertForbidden();
    $this->post(route('admin.platform.clients.impersonate', $client))->assertForbidden();
});

test('closing, merging and impersonating require a recent password confirmation', function () {
    $this->flushSession();
    actingAsStaff(['core.clients.view', 'core.clients.close', 'core.clients.merge', 'core.clients.impersonate']);
    $client = Client::factory()->create();

    $this->post(route('admin.platform.clients.close', $client))->assertSessionHasErrors('password_confirmation');
    $this->postJson(route('admin.platform.clients.merge', $client), ['target_id' => $client->id])->assertStatus(423);
    $this->post(route('admin.platform.clients.impersonate', $client))->assertSessionHasErrors('password_confirmation');

    $this->travel(16)->minutes();
    confirmPassword();
    $this->post(route('admin.platform.clients.close', $client))->assertSessionHasNoErrors();
    expect($client->fresh()->status)->toBe('closed');
});

test('merging clients with different currencies is rejected (422)', function () {
    Currency::factory()->create(['code' => 'INR', 'name' => 'Indian Rupee', 'prefix' => '₹', 'exchange_rate' => 84, 'is_default' => false]);
    actingAsStaff(['core.clients.merge']);
    $source = Client::factory()->create(['currency' => 'USD']);
    $target = Client::factory()->create(['currency' => 'INR']);

    $this->postJson(route('admin.platform.clients.merge', $source), ['target_id' => $target->id, 'confirmation' => 'MERGE'])->assertUnprocessable()->assertJsonValidationErrors('target_id');
    $this->postJson(route('admin.platform.clients.merge', $source), ['target_id' => $source->id, 'confirmation' => 'MERGE'])->assertUnprocessable();
    $this->postJson(route('admin.platform.clients.merge', $source), ['target_id' => Client::factory()->create()->id])->assertJsonValidationErrors('confirmation');
});

test('merge preview validates the target and lists every participant', function () {
    app(ClientMergeRegistry::class)->participant('test.services', fn (int $sourceId) => ['label' => 'Services', 'count' => 3]);
    actingAsStaff(['core.clients.view', 'core.clients.merge']);
    $source = Client::factory()->withOwner()->create();
    $target = Client::factory()->create();
    Contact::factory()->count(2)->create(['client_id' => $source->id]);

    $this->getJson(route('admin.platform.clients.merge-preview', [$source, 'target_id' => $source->id]))
        ->assertJsonPath('problem', 'Choose a different client to merge into.')
        ->assertJsonPath('items', []);

    $items = collect($this->getJson(route('admin.platform.clients.merge-preview', [$source, 'target_id' => $target->id]))
        ->assertOk()
        ->assertJsonPath('problem', null)
        ->assertJsonPath('target.id', $target->id)
        ->json('items'))->keyBy('key');

    expect($items['core.contacts']['count'])->toBe(2)
        ->and($items['core.logins']['count'])->toBe(1)
        ->and($items['test.services'])->toMatchArray(['label' => 'Services', 'count' => 3]);
});

test('merging moves contacts and logins, closes the source and dispatches ClientMerged in the transaction', function () {
    Event::fake([ClientMerged::class]);
    actingAsStaff(['core.clients.view', 'core.clients.merge']);
    $source = Client::factory()->withOwner()->create();
    $target = Client::factory()->withOwner()->create();
    $sourceOwner = $source->users()->sole();
    Contact::factory()->count(2)->create(['client_id' => $source->id]);

    $this->post(route('admin.platform.clients.merge', $source), ['target_id' => $target->id, 'confirmation' => 'MERGE'])
        ->assertRedirect(route('admin.clients.show', $target));

    expect($target->contacts()->count())->toBe(2)
        ->and($source->fresh())->status->toBe('closed')->merged_into_client_id->toBe($target->id)
        ->and($source->users()->count())->toBe(0)
        ->and((bool) $target->users()->whereKey($sourceOwner->id)->sole()->pivot->is_owner)->toBeFalse();
    Event::assertDispatched(ClientMerged::class, fn (ClientMerged $event) => $event->sourceClientId === $source->id && $event->targetClientId === $target->id);

    $this->get(route('admin.clients.show', $source))->assertRedirect(route('admin.clients.show', $target));
    $this->post(route('admin.platform.clients.merge', $source), ['target_id' => $target->id, 'confirmation' => 'MERGE'])->assertSessionHasErrors('target_id');
});

test('a failing ClientMerged listener rolls the whole merge back', function () {
    Event::listen(ClientMerged::class, fn () => throw new RuntimeException('billing reconcile failed'));
    actingAsStaff(['core.clients.view', 'core.clients.merge']);
    $source = Client::factory()->create();
    $target = Client::factory()->create();
    Contact::factory()->create(['client_id' => $source->id]);

    $this->withoutExceptionHandling();
    expect(fn () => $this->post(route('admin.platform.clients.merge', $source), ['target_id' => $target->id, 'confirmation' => 'MERGE']))
        ->toThrow(RuntimeException::class);

    expect($source->fresh())->status->toBe('active')->merged_into_client_id->toBeNull()
        ->and($source->contacts()->count())->toBe(1);
});

test('a module can veto a merge', function () {
    app(ClientMergeRegistry::class)->participant('test.affiliates', fn () => ['label' => 'Affiliate', 'count' => 1, 'blocking' => 'Both clients are affiliates with commissions.']);
    actingAsStaff(['core.clients.merge']);
    $source = Client::factory()->create();
    $target = Client::factory()->create();

    $this->post(route('admin.platform.clients.merge', $source), ['target_id' => $target->id, 'confirmation' => 'MERGE'])
        ->assertSessionHasErrors(['target_id' => 'Both clients are affiliates with commissions.']);
    expect($source->fresh()->merged_into_client_id)->toBeNull();
});

test('the profile form cannot close, reopen or revive a client', function () {
    actingAsStaff(['core.clients.view', 'core.clients.update']);
    $client = Client::factory()->create();
    $closed = Client::factory()->create(['status' => 'closed']);
    $payload = fn (Client $c, ?string $status) => array_filter([...$c->only(['first_name', 'last_name', 'email', 'currency']), 'status' => $status]);

    $this->put(route('admin.clients.update', $client), $payload($client, 'closed'))->assertSessionHasErrors('status');
    $this->put(route('admin.clients.update', $closed), $payload($closed, 'active'))->assertSessionHasErrors('status');
    $this->put(route('admin.clients.update', $closed), $payload($closed, null))->assertSessionHasNoErrors();

    $closed->forceFill(['merged_into_client_id' => $client->id])->save();
    $this->put(route('admin.clients.update', $closed), $payload($closed, null))->assertNotFound();

    expect($client->fresh()->status)->toBe('active')->and($closed->fresh()->status)->toBe('closed');
});

test('a client login\'s 2FA can be reset with core.users.reset_2fa and the user is emailed', function () {
    Notification::fake();
    Event::fake([TwoFactorReset::class]);
    $staff = actingAsStaff(['core.clients.view', 'core.users.reset_2fa']);
    $client = Client::factory()->create();
    $login = User::factory()->withTwoFactor()->create();
    $client->users()->attach($login, ['is_owner' => true]);

    $this->post(route('admin.platform.clients.users.reset-two-factor', [$client, $login]))->assertRedirect();

    expect($login->fresh()->two_factor_confirmed_at)->toBeNull();
    Notification::assertSentTo($login, SecurityNotice::class);
    Event::assertDispatched(TwoFactorReset::class, fn (TwoFactorReset $event) => $event->userId === $login->id && $event->resetByUserId === $staff->id);

    actingAsStaff(['core.clients.view', 'core.users.manage']);
    $this->post(route('admin.platform.clients.users.reset-two-factor', [$client, $login]))->assertForbidden();
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
            ->where('activity', null)
            ->where('areas', ['billing', 'services', 'domains', 'support', 'profile', 'contacts'])
            ->where('can.manageContacts', true)
            ->where('can.impersonate', false)
            ->where('can.merge', false));
});

test('client-login management is not reachable through core.* wildcards', function () {
    actingAsStaff(['core.*']);
    $client = Client::factory()->withOwner()->create();
    $outsider = User::factory()->create(['email' => 'outsider@evil.test']);

    $this->post(route('admin.platform.clients.users.store', $client), ['email' => $outsider->email, 'name' => 'x', 'permissions' => []])->assertForbidden();
    $this->post(route('admin.platform.clients.users.transfer', [$client, $client->users()->sole()]))->assertForbidden();
    $this->post(route('admin.platform.clients.users.reset-two-factor', [$client, $client->users()->sole()]))->assertForbidden();

    expect($client->users()->count())->toBe(1);
});

test('adding a login or changing the owner requires a recent password confirmation', function () {
    $this->flushSession();
    actingAsStaff(['core.clients.view', 'core.users.manage']);
    $client = Client::factory()->withOwner()->create();
    $helper = User::factory()->create();
    $client->users()->attach($helper, ['is_owner' => false, 'permissions' => json_encode([])]);

    $this->post(route('admin.platform.clients.users.store', $client), ['email' => 'new@example.com', 'name' => 'New', 'permissions' => []])->assertSessionHasErrors('password_confirmation');
    $this->post(route('admin.platform.clients.users.transfer', [$client, $helper]))->assertSessionHasErrors('password_confirmation');
    $this->put(route('admin.platform.clients.users.update', [$client, $helper]), ['permissions' => ['billing']])->assertSessionHasErrors('password_confirmation');
    $this->delete(route('admin.platform.clients.users.destroy', [$client, $helper]))->assertSessionHasErrors('password_confirmation');

    expect($client->users()->count())->toBe(2)
        ->and((bool) $client->users()->whereKey($helper->id)->sole()->pivot->is_owner)->toBeFalse();
});

test('the account owner and the affected user are emailed when a login is added or ownership moves', function () {
    Notification::fake();
    actingAsStaff(['core.clients.view', 'core.users.manage']);
    $client = Client::factory()->withOwner()->create();
    $owner = $client->users()->sole();
    $existing = User::factory()->create();

    $this->post(route('admin.platform.clients.users.store', $client), ['email' => $existing->email, 'name' => 'x', 'permissions' => ['billing']])->assertRedirect();

    Notification::assertSentTo($owner, SecurityNotice::class, fn (SecurityNotice $notice) => $notice->subject === 'A login was added to your account');
    Notification::assertSentTo($existing, SecurityNotice::class);

    $this->post(route('admin.platform.clients.users.transfer', [$client, $existing]))->assertRedirect();

    Notification::assertSentTo($owner, SecurityNotice::class, fn (SecurityNotice $notice) => $notice->subject === 'Your account has a new owner');
    Notification::assertSentTo($existing, SecurityNotice::class, fn (SecurityNotice $notice) => $notice->subject === 'You are now the account owner');
});
