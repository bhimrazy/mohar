<?php

use App\Models\User;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Contact;
use App\Modules\Core\Support\Settings;
use App\Modules\Support\Actions\AddNote;
use App\Modules\Support\Enums\TicketStatus;
use App\Modules\Support\Models\Department;
use App\Modules\Support\Models\Ticket;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/helpers.php';

function guestPayload(Department $department, array $overrides = []): array
{
    return [
        'department_id' => $department->id,
        'name' => 'Gina Guest',
        'email' => 'Gina@Example.com',
        'subject' => 'Pre-sales question',
        'body' => 'Do you support PHP 8.4?',
        ...$overrides,
    ];
}

test('a guest with a new email opens an open ticket that is reachable only with the right key', function () {
    $department = Department::factory()->create();

    $response = $this->post(route('support.tickets.store'), guestPayload($department));

    $ticket = Ticket::query()->sole();
    $response->assertRedirect(route('support.tickets.show', ['number' => $ticket->number, 'key' => $ticket->access_key]));
    expect($ticket)->client_id->toBeNull()->status->toBe(TicketStatus::Open)->requester_email->toBe('gina@example.com');

    $this->get(route('support.tickets.show', ['number' => $ticket->number, 'key' => $ticket->access_key]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('public/support/tickets/show')
            ->where('ticket.number', $ticket->number)
            ->missing('ticket.id')
            ->has('messages', 1));

    $this->get(route('support.tickets.show', ['number' => $ticket->number, 'key' => str_repeat('x', 32)]))->assertNotFound();
    $this->get(route('support.tickets.show', ['number' => $ticket->number]))->assertNotFound();
});

test('a guest email matching exactly one contact attaches the ticket to that client, flagged and without the key', function () {
    $client = Client::factory()->create();
    Contact::factory()->for($client)->create(['email' => 'gina@example.com']);

    $response = $this->post(route('support.tickets.store'), guestPayload(Department::factory()->create()));

    $ticket = Ticket::query()->sole();
    $response->assertRedirect(route('support.tickets.submitted'));
    expect($ticket->client_id)->toBe($client->id)
        ->and($ticket->contact_id)->not->toBeNull()
        ->and($ticket->is_unverified_requester)->toBeTrue()
        ->and((string) $response->headers->get('Location'))->not->toContain($ticket->access_key);

    $this->get(route('support.tickets.submitted'))
        ->assertInertia(fn (Assert $page) => $page->component('public/support/tickets/submitted')
            ->where('number', $ticket->number)
            ->where('email', 'g•••@example.com')
            ->missing('key'));
    $this->get(route('support.tickets.submitted'))->assertRedirect(route('support.tickets.create'));
});

test('staff see unverified requesters and can mark them verified', function () {
    Contact::factory()->for(Client::factory())->create(['email' => 'gina@example.com']);
    $department = Department::factory()->create();
    $this->post(route('support.tickets.store'), guestPayload($department));
    $ticket = Ticket::query()->sole();
    supportStaff([$department]);

    $this->get(route('admin.support.tickets.show', $ticket))->assertInertia(fn (Assert $page) => $page->where('ticket.is_unverified_requester', true));
    $this->patch(route('admin.support.tickets.update', $ticket), ['is_unverified_requester' => false])->assertRedirect();

    expect($ticket->fresh()->is_unverified_requester)->toBeFalse();
});

test('an ambiguous guest email stays a guest ticket', function () {
    Contact::factory()->for(Client::factory())->create(['email' => 'gina@example.com']);
    Client::factory()->create(['email' => 'gina@example.com']);

    $this->post(route('support.tickets.store'), guestPayload(Department::factory()->create()))->assertRedirect();

    expect(Ticket::query()->sole()->client_id)->toBeNull();
});

test('clients-only departments are hidden from guests and posting to them is forbidden', function () {
    $public = Department::factory()->create(['name' => 'Sales']);
    $clientsOnly = Department::factory()->clientsOnly()->create(['name' => 'Technical']);
    Department::factory()->hidden()->create(['name' => 'Secret']);

    $this->get(route('support.tickets.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('public/support/tickets/create')
            ->has('departments', 1)
            ->where('departments.0.value', (string) $public->id));

    $this->post(route('support.tickets.store'), guestPayload($clientsOnly))->assertForbidden();
    expect(Ticket::query()->count())->toBe(0);
});

test('clients-only departments can be shown to guests by setting', function () {
    app(Settings::class)->set('support.show_clients_only_to_guests', true);
    $department = Department::factory()->clientsOnly()->create();

    $this->post(route('support.tickets.store'), guestPayload($department))->assertRedirect();

    expect(Ticket::query()->count())->toBe(1);
});

test('hidden departments are listed only through their direct link', function () {
    $hidden = Department::factory()->hidden()->create(['slug' => 'vip']);

    $this->get(route('support.tickets.create', ['department' => 'vip']))
        ->assertInertia(fn (Assert $page) => $page->has('departments', 1)->where('selected', (string) $hidden->id));
});

test('guest submissions are validated and the honeypot rejects bots', function () {
    $department = Department::factory()->create();

    $this->post(route('support.tickets.store'), guestPayload($department, ['email' => 'nope', 'subject' => '']))
        ->assertSessionHasErrors(['email', 'subject']);
    $this->post(route('support.tickets.store'), guestPayload($department, ['website' => 'http://spam']))
        ->assertSessionHasErrors('website');

    expect(Ticket::query()->count())->toBe(0);
});

test('guest submissions are rate limited per IP', function () {
    $department = Department::factory()->create();

    foreach (range(1, 6) as $attempt) {
        $this->post(route('support.tickets.store'), guestPayload($department, ['subject' => "Attempt {$attempt}"]))->assertRedirect();
    }

    $this->post(route('support.tickets.store'), guestPayload($department))->assertTooManyRequests();
});

test('guests reply and close through their key and never see internal notes', function () {
    $ticket = guestTicket();
    $staff = supportStaff([$ticket->department]);
    app(AddNote::class)->handle($ticket, $staff, 'Internal: probably a reseller.');
    auth()->logout();

    $this->get(route('support.tickets.show', ['number' => $ticket->number, 'key' => $ticket->access_key]))
        ->assertInertia(fn (Assert $page) => $page->has('messages', 1)->where('messages.0.type', 'reply'));

    $this->post(route('support.tickets.reply', ['number' => $ticket->number, 'key' => $ticket->access_key]), ['body' => 'Thanks!'])->assertRedirect();
    expect($ticket->fresh()->status)->toBe(TicketStatus::CustomerReply);

    $this->post(route('support.tickets.reply', ['number' => $ticket->number, 'key' => 'wrong']), ['body' => 'Hijack'])->assertNotFound();

    $this->post(route('support.tickets.close', ['number' => $ticket->number, 'key' => $ticket->access_key]))->assertRedirect();
    expect($ticket->fresh())->status->toBe(TicketStatus::Closed)->closed_by->value->toBe('client');
});

test('signed-in clients are sent from the guest form to the client area', function () {
    [, $client] = actingAsClient();

    $this->get(route('support.tickets.create'))->assertRedirect(route('client.support.tickets.create'));
    expect(User::query()->count())->toBe(1)->and($client->exists)->toBeTrue();
});

test('guest ticket pages send no referrer', function () {
    $ticket = guestTicket();

    $this->get(route('support.tickets.show', ['number' => $ticket->number, 'key' => $ticket->access_key]))
        ->assertHeader('Referrer-Policy', 'no-referrer');
});
