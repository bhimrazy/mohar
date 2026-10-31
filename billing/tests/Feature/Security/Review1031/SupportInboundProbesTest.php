<?php

/*
 * Security round 2026-10-31, inbound support track (M2). RequesterResolver::match() attaches an inbound email to the
 * client whose `clients.email` equals the sender, whoever set that address. A signed-in client can set the account
 * email to any address in the client area (EmailSource::Client, never verified), so mail from that address opens a
 * ticket on the attacker's account, which the attacker then reads under Support. The same holds for a self-registered
 * owner whose address was never verified. Panel (staff-entered) contacts are vouched for, and probes C2–C4 pin that
 * the rest of the matching fails safe.
 */

use App\Models\User;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\Currency;
use App\Modules\Core\Support\Settings;
use App\Modules\Support\Actions\PostReply;
use App\Modules\Support\Data\TicketAuthor;
use App\Modules\Support\Drivers\Inbound\GenericDriver;
use App\Modules\Support\Models\Department;
use App\Modules\Support\Models\Ticket;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

require_once dirname(__DIR__, 2).'/Support/helpers.php';

beforeEach(function () {
    Storage::fake('objects');
    app(Settings::class)->set('support.inbound_secret', 'test-secret');
    $this->department = Department::factory()->create(['email' => 'help@support.example.test']);
});

function probeInbound(string $from, array $overrides = []): TestResponse
{
    $body = (string) json_encode([
        'message_id' => '<'.uniqid('m', true).'@mail.example>',
        'from' => $from,
        'to' => ['help@support.example.test'],
        'subject' => 'Transfer auth code for my domain',
        'text' => 'Please send the EPP code for victim-site.com.np.',
        'headers' => [],
        ...$overrides,
    ]);

    return test()->call('POST', route('support.inbound.email', 'generic'), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_SUPPORT_SIGNATURE' => GenericDriver::sign($body, 'test-secret'),
    ], $body);
}

test('C1 an account email the client typed in and nobody verified does not pull a stranger\'s mail into that account', function () {
    [, $attacker] = actingAsClient(Client::factory()->create(['country' => 'NP']));

    $this->put(route('client.account.update'), [
        'first_name' => 'Mallory', 'last_name' => 'M', 'email' => 'stranger@victim.example',
        'address_1' => 'Street 1', 'city' => 'Kathmandu', 'postcode' => '44600', 'country' => 'NP',
    ])->assertSessionHasNoErrors();
    expect($attacker->fresh()->email)->toBe('stranger@victim.example');

    probeInbound('Sam Stranger <stranger@victim.example>')->assertCreated();

    $ticket = Ticket::query()->sole();
    expect($ticket->client_id)->toBeNull();

    // And so the attacker cannot read it in the client area.
    $this->get(route('client.support.tickets.show', $ticket->number))->assertNotFound();
});

test('C2 a staff-entered contact on two accounts is ambiguous: the mail opens a guest ticket (holds)', function () {
    $a = Client::factory()->create();
    $b = Client::factory()->create();
    Contact::factory()->for($a)->create(['email' => 'shared@corp.example']);
    Contact::factory()->for($b)->create(['email' => 'shared@corp.example']);

    probeInbound('shared@corp.example')->assertCreated();

    expect(Ticket::query()->sole()->client_id)->toBeNull();
});

test('C3 a contact of another account cannot reply by email to this account\'s ticket, even threaded (holds)', function () {
    Currency::factory()->create();
    $victim = Client::factory()->create(['email' => 'owner@victim.example']);
    $ticket = clientTicket($victim, $this->department);
    $staff = supportStaff([$this->department]);
    $reply = app(PostReply::class)->handle($ticket, TicketAuthor::staff($staff), 'Please confirm.');
    Contact::factory()->for(Client::factory()->create())->create(['email' => 'outsider@other.example']);

    probeInbound('outsider@other.example', ['in_reply_to' => $reply->email_message_id, 'text' => 'Confirmed, send it to me.'])
        ->assertJsonPath('status', 'blocked');
    expect($ticket->messages()->count())->toBe(2);
});

test('C4 a matched contact gets a ticket on its account but no login (holds)', function () {
    $client = Client::factory()->create();
    Contact::factory()->for($client)->create(['email' => 'accounts@corp.example']);

    probeInbound('accounts@corp.example')->assertCreated();

    expect(Ticket::query()->sole()->client_id)->toBe($client->id)
        ->and(User::query()->where('email', 'accounts@corp.example')->exists())->toBeFalse();
});
