<?php

use App\Models\User;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Support\Settings;
use App\Modules\Notifications\Models\ClientEmailPreference;
use App\Modules\Notifications\Models\EmailMessage;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/helpers.php';

it('lets the owner change preferences for the account and its contacts', function () {
    [, $client] = actingAsClient();
    $contact = contactFor($client, 'accounts@example.test', ['invoice' => true]);

    $this->get(route('client.notifications.preferences.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('client/notifications/preferences')->has('rows', 2));

    $this->put(route('client.notifications.preferences.update'), ['preferences' => [
        ['scope' => 'owner', 'categories' => ['invoice' => false, 'general' => true]],
        ['scope' => 'contact:'.$contact->id, 'categories' => ['domain' => true]],
    ]])->assertSessionHasNoErrors();

    expect(ClientEmailPreference::query()->where('client_id', $client->id)->sole()->wants('invoice'))->toBeFalse()
        ->and($contact->fresh()->email_preferences)->toMatchArray(['invoice' => true, 'domain' => true]);
});

it('blocks turning off the last invoice recipient (criterion 2)', function () {
    [, $client] = actingAsClient();

    $this->put(route('client.notifications.preferences.update'), ['preferences' => [
        ['scope' => 'owner', 'categories' => ['invoice' => false]],
    ]])->assertSessionHasErrors('preferences');

    expect(ClientEmailPreference::query()->where('client_id', $client->id)->exists())->toBeFalse();
});

it('cannot change contacts of another account (IDOR)', function () {
    actingAsClient();
    $other = Client::factory()->create();
    $foreign = contactFor($other, 'victim@example.test', ['invoice' => true, 'domain' => true]);

    $this->put(route('client.notifications.preferences.update'), ['preferences' => [
        ['scope' => 'contact:'.$foreign->id, 'categories' => ['invoice' => false]],
    ]])->assertSessionHasErrors('preferences');

    expect($foreign->fresh()->email_preferences['invoice'])->toBeTrue();
});

it('limits non-owner logins to their own row', function () {
    [, $client] = actingAsClient();
    $member = User::factory()->create();
    $client->users()->attach($member, ['is_owner' => false, 'permissions' => json_encode(['billing'])]);
    $this->actingAs($member);

    $this->put(route('client.notifications.preferences.update'), ['preferences' => [
        ['scope' => 'owner', 'categories' => ['general' => false]],
    ]])->assertSessionHasErrors('preferences');

    $this->put(route('client.notifications.preferences.update'), ['preferences' => [
        ['scope' => 'user:'.$member->id, 'categories' => ['general' => false]],
    ]])->assertSessionHasNoErrors();
});

it('locks preferences when the install does not let clients manage them', function () {
    actingAsClient();
    app(Settings::class)->set('notifications.client_can_manage_preferences', false);

    $this->put(route('client.notifications.preferences.update'), ['preferences' => [
        ['scope' => 'owner', 'categories' => ['general' => false]],
    ]])->assertForbidden();
});

it('shows only this account\'s sent emails in the history (IDOR)', function () {
    [, $client] = actingAsClient();
    $mine = EmailMessage::factory()->create(['client_id' => $client->id]);
    $theirs = EmailMessage::factory()->create(['client_id' => Client::factory()->create()->id]);

    $this->get(route('client.notifications.emails.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('client/notifications/emails/index')->has('messages.data', 1)->where('messages.data.0.uuid', $mine->uuid));

    $this->get(route('client.notifications.emails.show', $mine->uuid))->assertOk();
    $this->get(route('client.notifications.emails.show', $theirs->uuid))->assertNotFound();
});
