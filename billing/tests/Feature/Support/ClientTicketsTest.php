<?php

use App\Models\User;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Core\Support\Settings;
use App\Modules\Support\Actions\AddNote;
use App\Modules\Support\Actions\MergeTickets;
use App\Modules\Support\Actions\PostReply;
use App\Modules\Support\Data\TicketAuthor;
use App\Modules\Support\Enums\TicketStatus;
use App\Modules\Support\Models\Department;
use App\Modules\Support\Models\Ticket;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/helpers.php';

test('clients list only their own account tickets', function () {
    [, $client] = actingAsClient();
    $mine = clientTicket($client);
    $other = clientTicket(Client::factory()->create());

    $this->get(route('client.support.tickets.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('client/support/tickets/index')
            ->has('tickets.data', 1)
            ->where('tickets.data.0.number', $mine->number)
            ->missing('tickets.data.0.id'));

    expect($other->number)->not->toBe($mine->number);
});

test('clients cannot view, reply to, close or rate tickets of other clients', function () {
    actingAsClient();
    $foreign = clientTicket(Client::factory()->create());
    $staffReply = app(PostReply::class)->handle($foreign, TicketAuthor::staff(User::factory()->staff()->create()), 'Hello');

    $this->get(route('client.support.tickets.show', $foreign->number))->assertNotFound();
    $this->post(route('client.support.tickets.reply', $foreign->number), ['body' => 'x'])->assertNotFound();
    $this->post(route('client.support.tickets.close', $foreign->number))->assertNotFound();
    $this->post(route('client.support.tickets.rate', [$foreign->number, $staffReply->id]), ['rating' => 1])->assertNotFound();

    expect($foreign->fresh()->status)->toBe(TicketStatus::Answered)
        ->and($staffReply->fresh()->rating)->toBeNull();
});

test('account users need the support permission', function () {
    Currency::factory()->create();
    $client = Client::factory()->create();
    $user = User::factory()->create();
    $client->users()->attach($user, ['is_owner' => false, 'permissions' => json_encode(['billing'])]);
    $this->actingAs($user);

    $this->get(route('client.support.tickets.index'))->assertForbidden();
    $this->post(route('client.support.tickets.store'), [])->assertForbidden();
});

test('clients open tickets with a related service id and attachments', function () {
    [$user, $client] = actingAsClient();
    $department = Department::factory()->clientsOnly()->create();

    $this->get(route('client.support.tickets.create'))
        ->assertInertia(fn (Assert $page) => $page->component('client/support/tickets/create')->has('departments', 1)->has('limits.extensions'));

    $this->post(route('client.support.tickets.store'), [
        'department_id' => $department->id,
        'subject' => 'Email not sending',
        'body' => 'SMTP times out.',
        'priority' => 'high',
        'service_id' => 42,
    ])->assertRedirect();

    $ticket = Ticket::query()->sole();
    expect($ticket)->client_id->toBe($client->id)->user_id->toBe($user->id)->service_id->toBe(42)
        ->priority->value->toBe('high')->status->toBe(TicketStatus::Open);
});

test('the client thread hides notes and staff emails', function () {
    [, $client] = actingAsClient();
    $ticket = clientTicket($client);
    $staff = User::factory()->staff()->create(['email' => 'agent@internal.test']);
    app(AddNote::class)->handle($ticket, $staff, 'Internal only');
    app(PostReply::class)->handle($ticket, TicketAuthor::staff($staff), 'Public answer');

    $this->get(route('client.support.tickets.show', $ticket->number))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('client/support/tickets/show')
            ->has('messages', 2)
            ->where('messages.1.author_type', 'staff')
            ->where('messages.1.can_rate', true)
            ->missing('messages.1.author_email')
            ->missing('messages.1.body'));
});

test('clients close tickets unless prevented', function () {
    [, $client] = actingAsClient();
    $ticket = clientTicket($client);

    $this->post(route('client.support.tickets.close', $ticket->number))->assertRedirect();
    expect($ticket->fresh())->status->toBe(TicketStatus::Closed)->closed_by->value->toBe('client');

    $locked = clientTicket($client, Department::factory()->create(['prevent_client_close' => true]));
    expect($locked->prevent_client_close)->toBeTrue();
    $this->post(route('client.support.tickets.close', $locked->number))->assertForbidden();
    expect($locked->fresh()->status)->toBe(TicketStatus::Open);
});

test('clients rate staff replies once per message and may change the rating', function () {
    [, $client] = actingAsClient();
    $ticket = clientTicket($client);
    $reply = app(PostReply::class)->handle($ticket, TicketAuthor::staff(User::factory()->staff()->create()), 'Fixed');
    $ownMessage = $ticket->messages()->firstOrFail();

    $this->post(route('client.support.tickets.rate', [$ticket->number, $reply->id]), ['rating' => 4])->assertRedirect();
    $this->post(route('client.support.tickets.rate', [$ticket->number, $reply->id]), ['rating' => 5])->assertRedirect();
    expect($reply->fresh()->rating)->toBe(5);

    $this->post(route('client.support.tickets.rate', [$ticket->number, $ownMessage->id]), ['rating' => 5])->assertSessionHasErrors('rating');
    $this->post(route('client.support.tickets.rate', [$ticket->number, $reply->id]), ['rating' => 9])->assertSessionHasErrors('rating');

    app(Settings::class)->set('support.reply_ratings_enabled', false);
    $this->post(route('client.support.tickets.rate', [$ticket->number, $reply->id]), ['rating' => 1])->assertSessionHasErrors('rating');
    expect($reply->fresh()->rating)->toBe(5);
});

test('client urls of merged tickets redirect to the target', function () {
    [$user, $client] = actingAsClient();
    $older = clientTicket($client);
    $newer = clientTicket($client);
    app(MergeTickets::class)->handle([$older->id, $newer->id], User::factory()->staff()->create());

    $this->get(route('client.support.tickets.show', $newer->number))->assertRedirect(route('client.support.tickets.show', $older->number));
    expect($user->id)->toBeInt();
});
