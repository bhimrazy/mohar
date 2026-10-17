<?php

use App\Models\User;
use App\Modules\Core\Models\Client;
use App\Modules\Support\Actions\AddNote;
use App\Modules\Support\Actions\PostReply;
use App\Modules\Support\Data\TicketAuthor;
use App\Modules\Support\Enums\TicketStatus;
use App\Modules\Support\Models\CannedReply;
use App\Modules\Support\Models\Department;
use App\Modules\Support\Models\SpamRule;
use App\Modules\Support\Models\StaffSignature;
use App\Modules\Support\Models\Ticket;
use App\Modules\Support\Models\TicketEvent;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/helpers.php';

test('the ticket view shows thread, notes, canned replies and options', function () {
    $department = Department::factory()->create();
    $ticket = clientTicket(Client::factory()->create(), $department);
    $staff = supportStaff([$department]);
    app(AddNote::class)->handle($ticket, $staff, 'Internal');
    CannedReply::query()->create(['name' => 'Greeting', 'body' => 'Hello {{ requester.name }}']);

    $this->get(route('admin.support.tickets.show', $ticket))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('admin/support/tickets/show')
            ->where('ticket.id', $ticket->id)
            ->where('ticket.version', $ticket->fresh()->version)
            ->has('messages', 2)
            ->where('messages.1.type', 'note')
            ->has('cannedReplies', 1)
            ->has('staff', 1)
            ->has('events')
            ->where('can.reply', true));
});

test('the queue filters by view, status, priority, department and client', function () {
    $department = Department::factory()->create();
    $client = Client::factory()->create();
    $mine = clientTicket($client, $department);
    $other = clientTicket(Client::factory()->create(), $department);
    $closed = clientTicket($client, $department);
    $closed->forceFill(['status' => TicketStatus::Closed])->save();
    $staff = supportStaff([$department]);
    $mine->forceFill(['assigned_to' => $staff->id, 'priority' => 'urgent'])->save();

    $ids = fn (array $query) => collect($this->get(route('admin.support.tickets.index', $query))->inertiaProps('tickets.data'))->pluck('id')->sort()->values()->all();

    expect($ids([]))->toBe(collect([$mine->id, $other->id])->sort()->values()->all())
        ->and($ids(['view' => 'mine']))->toBe([$mine->id])
        ->and($ids(['view' => 'closed']))->toBe([$closed->id])
        ->and($ids(['priority' => 'urgent']))->toBe([$mine->id])
        ->and($ids(['client' => $other->client_id]))->toBe([$other->id])
        ->and($ids(['view' => 'awaiting']))->toBe(collect([$mine->id, $other->id])->sort()->values()->all())
        ->and($ids(['search' => $mine->number]))->toBe([$mine->id]);
});

test('awaiting view orders by the oldest unanswered client message so bumping does not help', function () {
    $department = Department::factory()->create();
    $bumper = clientTicket(Client::factory()->create(), $department);
    $this->travel(10)->minutes();
    $patient = clientTicket(Client::factory()->create(), $department);
    $this->travel(10)->minutes();
    app(PostReply::class)->handle($bumper, TicketAuthor::client(User::query()->findOrFail($bumper->user_id)), 'Any news?');
    supportStaff([$department]);

    $order = collect($this->get(route('admin.support.tickets.index', ['view' => 'awaiting']))->inertiaProps('tickets.data'))->pluck('id')->all();

    expect($order)->toBe([$bumper->id, $patient->id]);
});

test('pinned tickets list first', function () {
    $department = Department::factory()->create();
    $first = clientTicket(Client::factory()->create(), $department);
    $this->travel(1)->minutes();
    $pinned = clientTicket(Client::factory()->create(), $department);
    $first->forceFill(['is_pinned' => true])->save();
    supportStaff([$department]);

    expect($this->get(route('admin.support.tickets.index'))->inertiaProps('tickets.data.0.id'))->toBe($first->id)
        ->and($pinned->id)->not->toBe($first->id);
});

test('staff open tickets for clients as answered', function () {
    $department = Department::factory()->create();
    $client = Client::factory()->create();
    supportStaff([$department], ['support.tickets.view', 'support.tickets.open']);

    $this->get(route('admin.support.tickets.create', ['client' => $client->id]))
        ->assertInertia(fn (Assert $page) => $page->component('admin/support/tickets/create')->where('client.id', $client->id));

    $this->post(route('admin.support.tickets.store'), [
        'department_id' => $department->id,
        'client_id' => $client->id,
        'requester_name' => 'Pat Client',
        'requester_email' => 'pat@example.com',
        'subject' => 'Your renewal',
        'body' => 'Your plan renews soon.',
        'priority' => 'low',
    ])->assertRedirect();

    $ticket = Ticket::query()->sole();
    expect($ticket)->status->toBe(TicketStatus::Answered)->source->value->toBe('staff')->client_id->toBe($client->id)
        ->and($ticket->first_staff_reply_at)->not->toBeNull();
});

test('staff edit messages with history and cannot delete the first message', function () {
    $department = Department::factory()->create();
    $ticket = clientTicket(Client::factory()->create(), $department);
    supportStaff([$department], ['support.tickets.view', 'support.tickets.reply', 'support.tickets.edit_messages']);
    $first = $ticket->messages()->firstOrFail();
    $this->post(route('admin.support.tickets.replies.store', $ticket), ['body' => 'Reply'])->assertRedirect();
    $reply = $ticket->messages()->reorder()->latest('id')->firstOrFail();

    $this->patch(route('admin.support.tickets.messages.update', [$ticket, $first]), ['body' => 'Edited **text**'])->assertRedirect();
    expect($first->fresh())->body->toBe('Edited **text**')->body_html->toContain('<strong>text</strong>')->edited_at->not->toBeNull()
        ->and(TicketEvent::query()->where('type', 'message_edited')->sole()->meta['previous_body'])->toBe('My site is down.');

    $this->delete(route('admin.support.tickets.messages.destroy', [$ticket, $first]))->assertSessionHasErrors('message');
    $this->delete(route('admin.support.tickets.messages.destroy', [$ticket, $reply]))->assertRedirect();
    expect($ticket->messages()->count())->toBe(1);
});

test('editing messages requires its own permission', function () {
    $department = Department::factory()->create();
    $ticket = clientTicket(Client::factory()->create(), $department);
    supportStaff([$department]);

    $this->patch(route('admin.support.tickets.messages.update', [$ticket, $ticket->messages()->firstOrFail()]), ['body' => 'x'])->assertForbidden();
});

test('options update status, tags, cc and pin with audit entries', function () {
    $department = Department::factory()->create();
    $ticket = clientTicket(Client::factory()->create(), $department);
    supportStaff([$department]);

    $this->patch(route('admin.support.tickets.update', $ticket), [
        'status' => 'in_progress',
        'tags' => 'vip, outage',
        'cc' => ['Boss@Example.com'],
        'is_pinned' => true,
    ])->assertRedirect(route('admin.support.tickets.show', $ticket));

    expect($ticket->fresh())->status->toBe(TicketStatus::InProgress)->tags->toBe(['vip', 'outage'])->cc->toBe(['boss@example.com'])->is_pinned->toBeTrue()
        ->and(TicketEvent::query()->where('ticket_id', $ticket->id)->pluck('type')->all())->toContain('status', 'tags', 'cc', 'pinned');

    $this->patch(route('admin.support.tickets.update', $ticket), ['cc' => array_map(fn ($i) => "u{$i}@x.test", range(1, 11))])->assertSessionHasErrors('cc');
});

test('bulk close and delete respect department access', function () {
    $department = Department::factory()->create();
    $mine = clientTicket(Client::factory()->create(), $department);
    $foreign = clientTicket(Client::factory()->create());
    supportStaff([$department], ['support.tickets.view', 'support.tickets.manage', 'support.tickets.delete']);

    $this->post(route('admin.support.tickets.bulk'), ['action' => 'close', 'tickets' => [$mine->id, $foreign->id]])->assertRedirect();
    expect($mine->fresh()->status)->toBe(TicketStatus::Closed)->and($foreign->fresh()->status)->toBe(TicketStatus::Open);

    $this->post(route('admin.support.tickets.bulk'), ['action' => 'delete', 'tickets' => [$mine->id]])->assertRedirect();
    expect(Ticket::query()->whereKey($mine->id)->exists())->toBeFalse();
});

test('staff save a signature', function () {
    $staff = supportStaff();

    $this->put(route('admin.support.signature.update'), ['signature' => '-- Sam'])->assertRedirect();
    expect(StaffSignature::query()->where('user_id', $staff->id)->value('signature'))->toBe('-- Sam');

    $this->put(route('admin.support.signature.update'), ['signature' => ''])->assertRedirect();
    expect(StaffSignature::query()->count())->toBe(0);
});

test('staff may only link services of the ticket client', function () {
    $department = Department::factory()->create();
    $client = Client::factory()->create();
    $ticket = clientTicket($client, $department);
    $guest = guestTicket($department);
    fakeServiceDirectory([$client->id => [5]]);
    supportStaff([$department]);

    $this->get(route('admin.support.tickets.show', $ticket))->assertInertia(fn (Assert $page) => $page->where('services.0.id', 5));
    $this->patch(route('admin.support.tickets.update', $ticket), ['service_id' => 6])->assertSessionHasErrors('service_id');
    $this->patch(route('admin.support.tickets.update', $ticket), ['service_id' => 5])->assertRedirect();
    $this->patch(route('admin.support.tickets.update', $guest), ['service_id' => 5])->assertSessionHasErrors('service_id');

    expect($ticket->fresh()->service_id)->toBe(5)->and($guest->fresh()->service_id)->toBeNull();
});

test('merge candidates are limited to the staff member\'s departments', function () {
    $mine = Department::factory()->create();
    $client = Client::factory()->create();
    $ticket = clientTicket($client, $mine);
    $sibling = clientTicket($client, $mine);
    clientTicket($client, Department::factory()->create());
    supportStaff([$mine]);

    $this->get(route('admin.support.tickets.show', $ticket))
        ->assertInertia(fn (Assert $page) => $page->has('mergeCandidates', 1)->where('mergeCandidates.0.id', $sibling->id));
});

test('bulk block sender adds sender rules and closes the tickets', function () {
    $department = Department::factory()->create();
    $spam = guestTicket($department, 'Spammer@Example.com');
    $again = guestTicket($department, 'spammer@example.com');
    supportStaff([$department]);

    $this->post(route('admin.support.tickets.bulk'), ['action' => 'block_sender', 'tickets' => [$spam->id, $again->id]])->assertRedirect();

    expect(SpamRule::query()->where('field', 'sender')->pluck('value')->all())->toBe(['spammer@example.com'])
        ->and($spam->fresh()->status)->toBe(TicketStatus::Closed)
        ->and($again->fresh()->status)->toBe(TicketStatus::Closed);
});
