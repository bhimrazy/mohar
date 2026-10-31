<?php

/*
 * Inbound mail attaches to an account only through an address someone proved or vouched for (security review
 * 2026-10-31, M2): a staff or import account email, a verified login, or a contact.
 */

use App\Models\User;
use App\Modules\Core\Enums\EmailSource;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Support\Settings;
use App\Modules\Support\Actions\PostReply;
use App\Modules\Support\Data\TicketAuthor;
use App\Modules\Support\Drivers\Inbound\GenericDriver;
use App\Modules\Support\Models\Department;
use App\Modules\Support\Models\Ticket;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    Storage::fake('objects');
    app(Settings::class)->set('support.inbound_secret', 'test-secret');
    $this->department = Department::factory()->create(['email' => 'help@support.example.test']);
});

function trustInbound(string $from, array $overrides = []): TestResponse
{
    $body = (string) json_encode([
        'message_id' => '<'.uniqid('m', true).'@mail.example>',
        'from' => $from,
        'to' => ['help@support.example.test'],
        'subject' => 'Question',
        'text' => 'Hello.',
        'headers' => [],
        ...$overrides,
    ]);

    return test()->call('POST', route('support.inbound.email', 'generic'), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_SUPPORT_SIGNATURE' => GenericDriver::sign($body, 'test-secret'),
    ], $body);
}

function clientTypedEmail(string $email): Client
{
    $client = Client::factory()->create(['email' => 'owner-'.uniqid().'@corp.example']);
    $client->forceFill(['email' => $email, 'email_source' => EmailSource::Client])->save();

    return $client->refresh();
}

test('staff and import account emails attach new mail; a client-typed one does not', function (EmailSource $source, bool $attached) {
    $client = Client::factory()->create(['email' => 'billing@corp.example']);
    $client->forceFill(['email_source' => $source])->save();

    trustInbound('billing@corp.example')->assertCreated();

    expect(Ticket::query()->sole()->client_id)->toBe($attached ? $client->id : null);
})->with([
    'staff' => [EmailSource::Staff, true],
    'import' => [EmailSource::Import, true],
    'client' => [EmailSource::Client, false],
]);

test('a client-typed account email counts once a login of that client verified it', function () {
    $client = clientTypedEmail('me@corp.example');
    $client->users()->attach(User::factory()->create(['email' => 'me@corp.example']), ['is_owner' => true]);

    trustInbound('me@corp.example')->assertCreated();

    expect(Ticket::query()->sole()->client_id)->toBe($client->id);
});

test('an unverified login, owner or sub-user, does not attach mail or lend its name', function (bool $owner) {
    $client = Client::factory()->create();
    $client->users()->attach(User::factory()->unverified()->create(['email' => 'sub@corp.example', 'name' => 'Sub User']), ['is_owner' => $owner]);

    trustInbound('sub@corp.example')->assertCreated();

    expect(Ticket::query()->sole())->client_id->toBeNull()->requester_name->toBe('sub');
})->with(['owner' => true, 'sub-user' => false]);

test('a client typing in a vouched address of another account does not make that account\'s mail ambiguous', function () {
    $victim = Client::factory()->create(['email' => 'owner@victim.example']);
    clientTypedEmail('owner@victim.example');

    trustInbound('owner@victim.example')->assertCreated();

    expect(Ticket::query()->sole()->client_id)->toBe($victim->id);
});

test('clients-only departments bounce a sender known only by a client-typed address', function () {
    $this->department->update(['clients_only' => true]);
    clientTypedEmail('typed@victim.example');

    trustInbound('typed@victim.example')->assertJsonPath('status', 'bounced_unregistered');

    expect(Ticket::query()->count())->toBe(0);
});

test('a threaded email reply from the client-typed account email is blocked; from a verified login it is added', function () {
    $client = Client::factory()->create(['email' => 'login@corp.example']);
    $ticket = clientTicket($client, $this->department);
    $client->forceFill(['email' => 'typed@victim.example', 'email_source' => EmailSource::Client])->save();
    $reply = app(PostReply::class)->handle($ticket, TicketAuthor::staff(supportStaff([$this->department])), 'Please confirm.');

    trustInbound('typed@victim.example', ['in_reply_to' => $reply->email_message_id])->assertJsonPath('status', 'blocked');

    $sub = User::factory()->create(['email' => 'sub@corp.example']);
    $client->users()->attach($sub, ['is_owner' => false]);
    trustInbound('sub@corp.example', ['in_reply_to' => $reply->email_message_id])->assertJsonPath('status', 'reply_added');

    $sub->forceFill(['email_verified_at' => null])->save();
    trustInbound('sub@corp.example', ['in_reply_to' => $reply->email_message_id])->assertJsonPath('status', 'blocked');
});

test('the public ticket form treats a client-typed address as a guest, not as that account', function () {
    clientTypedEmail('typed@victim.example');

    $this->post(route('support.tickets.store'), [
        'department_id' => $this->department->id, 'name' => 'Sam', 'email' => 'typed@victim.example',
        'subject' => 'Hello', 'body' => 'Question about a transfer.',
    ])->assertRedirect();

    expect(Ticket::query()->sole())->client_id->toBeNull()->is_unverified_requester->toBeFalse();
});
