<?php

/*
 * Review board probes for #537 (security M2).
 */

use App\Models\User;
use App\Modules\Core\Enums\EmailSource;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Support\Settings;
use App\Modules\Support\Actions\UpdateTicket;
use App\Modules\Support\Models\Department;
use App\Modules\Support\Models\Ticket;
use Illuminate\Support\Facades\Storage;

require_once dirname(__DIR__).'/Support/helpers.php';

beforeEach(function () {
    Storage::fake('objects');
    app(Settings::class)->set('support.inbound_secret', 'test-secret');
    $this->department = Department::factory()->create(['email' => 'help@support.example.test']);
});

test('P1 the public form does not show the access link for an address that is a registered account email, proved or not', function () {
    $client = Client::factory()->create(['email' => 'owner@victim.example']);
    $client->forceFill(['email_source' => EmailSource::Client])->save();
    $client->users()->attach(User::factory()->unverified()->create(['email' => 'owner@victim.example']), ['is_owner' => true]);

    $response = $this->post(route('support.tickets.store'), [
        'department_id' => $this->department->id, 'name' => 'Not Victim', 'email' => 'owner@victim.example',
        'subject' => 'Please change my account', 'body' => 'Hello.',
    ]);

    $response->assertRedirect(route('support.tickets.submitted'));
});

test('P2 linking a guest ticket to a client invalidates the access link the submitter was shown', function () {
    $ticket = Ticket::factory()->create(['department_id' => $this->department->id]);
    $key = $ticket->access_key;
    $client = Client::factory()->create();
    $staff = supportStaff([$this->department]);

    app(UpdateTicket::class)->handle($ticket, $staff, ['client_id' => $client->id]);

    $this->get(route('support.tickets.show', ['number' => $ticket->number, 'key' => $key]))->assertNotFound();
});
