<?php

use App\Events\Support\TicketClosed;
use App\Events\Support\TicketNoteAdded;
use App\Events\Support\TicketOpened;
use App\Events\Support\TicketReplied;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Support\Settings;
use App\Modules\Support\Actions\AddNote;
use App\Modules\Support\Actions\PostReply;
use App\Modules\Support\Data\TicketAuthor;
use App\Modules\Support\Enums\TicketStatus;
use App\Modules\Support\Events\TicketReopened;
use App\Modules\Support\Models\Department;
use App\Modules\Support\Models\StaffSignature;
use App\Modules\Support\Models\Ticket;
use App\Modules\Support\Models\TicketEvent;
use Illuminate\Support\Facades\Event;

require_once __DIR__.'/helpers.php';

test('ticket numbers follow the configured mask and are unique', function () {
    app(Settings::class)->set('support.ticket_number_format', 'ABC-%n%n%n');
    $client = Client::factory()->create();

    $numbers = collect(range(1, 5))->map(fn () => clientTicket($client)->number);

    expect($numbers->every(fn (string $number) => preg_match('/^ABC-\d{3}$/', $number) === 1))->toBeTrue()
        ->and($numbers->unique())->toHaveCount(5);
});

test('when every masked number collides the ticket id is appended', function () {
    app(Settings::class)->set('support.ticket_number_format', 'X');
    $client = Client::factory()->create();

    $first = clientTicket($client);
    $second = clientTicket($client);

    expect($first->number)->toBe('X')
        ->and($second->number)->toBe('X-'.$second->id);
});

test('client tickets open with status open and emit TicketOpened once', function () {
    Event::fake([TicketOpened::class]);
    $client = Client::factory()->create();
    $department = Department::factory()->create();

    $ticket = clientTicket($client, $department);

    expect($ticket->status)->toBe(TicketStatus::Open)
        ->and($ticket->access_key)->toHaveLength(32)
        ->and($ticket->messages()->count())->toBe(1);
    Event::assertDispatchedTimes(TicketOpened::class, 1);
    Event::assertDispatched(TicketOpened::class, fn (TicketOpened $event) => $event->ticketId === $ticket->id && ! $event->byStaff && $event->clientId === $client->id && $event->departmentId === $department->id);
});

test('a staff reply without explicit status sets answered and emits TicketReplied(byStaff) once', function () {
    $ticket = clientTicket(Client::factory()->create());
    $staff = supportStaff([$ticket->department]);
    Event::fake([TicketReplied::class]);

    app(PostReply::class)->handle($ticket, TicketAuthor::staff($staff), 'We are on it.');

    expect($ticket->fresh()->status)->toBe(TicketStatus::Answered);
    Event::assertDispatchedTimes(TicketReplied::class, 1);
    Event::assertDispatched(TicketReplied::class, fn (TicketReplied $event) => $event->byStaff && $event->ticketId === $ticket->id);
});

test('staff may choose another status when replying', function () {
    $ticket = clientTicket(Client::factory()->create());
    $staff = supportStaff([$ticket->department]);

    app(PostReply::class)->handle($ticket, TicketAuthor::staff($staff), 'Waiting on the datacenter.', status: TicketStatus::OnHold);

    expect($ticket->fresh()->status)->toBe(TicketStatus::OnHold);
});

test('a client reply on a closed ticket reopens it as customer_reply', function () {
    Event::fake([TicketReopened::class, TicketReplied::class]);
    [$user, $client] = actingAsClient();
    $ticket = clientTicket($client);
    $ticket->forceFill(['status' => TicketStatus::Closed, 'closed_at' => now(), 'closed_by' => 'staff'])->save();

    $this->post(route('client.support.tickets.reply', $ticket->number), ['body' => 'Still broken.'])
        ->assertRedirect(route('client.support.tickets.show', $ticket->number));

    expect($ticket->fresh())->status->toBe(TicketStatus::CustomerReply)->closed_at->toBeNull();
    Event::assertDispatched(TicketReopened::class, fn (TicketReopened $event) => $event->ticketId === $ticket->id);
    Event::assertDispatched(TicketReplied::class, fn (TicketReplied $event) => ! $event->byStaff);
});

test('a staff note leaves the status unchanged and emits no client-facing event', function () {
    $ticket = clientTicket(Client::factory()->create());
    $staff = supportStaff([$ticket->department]);
    Event::fake([TicketReplied::class, TicketClosed::class, TicketNoteAdded::class]);

    $note = app(AddNote::class)->handle($ticket, $staff, 'Customer is on the legacy plan.');

    expect($ticket->fresh()->status)->toBe(TicketStatus::Open)
        ->and($note->isNote())->toBeTrue();
    Event::assertNotDispatched(TicketReplied::class);
    Event::assertNotDispatched(TicketClosed::class);
    Event::assertDispatched(TicketNoteAdded::class, fn (TicketNoteAdded $event) => $event->replyId === $note->id && $event->staffUserId === $staff->id);
});

test('staff replies resolve merge tokens and append the staff signature', function () {
    $client = Client::factory()->create(['company_name' => 'Acme Ltd']);
    $ticket = clientTicket($client);
    $staff = supportStaff([$ticket->department]);
    StaffSignature::query()->create(['user_id' => $staff->id, 'signature' => '-- Sam, Support']);

    $message = app(PostReply::class)->handle($ticket, TicketAuthor::staff($staff), 'Hi {{ requester.first_name }}, ticket {{ ticket.number }} for {{ client.company_name }}.');

    expect($message->body)->toContain('ticket '.$ticket->number.' for Acme Ltd.')
        ->toContain('-- Sam, Support')
        ->not->toContain('{{');
});

test('user content is rendered as safe markdown', function () {
    $ticket = clientTicket(Client::factory()->create(), body: "**bold** <script>alert(1)</script> [x](javascript:alert(1)) [site](https://evil.example)\n\n<img src=x onerror=alert(1)>");
    $html = $ticket->messages()->firstOrFail()->body_html;

    expect($html)->toContain('<strong>bold</strong>')
        ->not->toContain('<script')
        ->not->toContain('onerror')
        ->not->toContain('javascript:')
        ->toContain('rel="nofollow noopener noreferrer"');
});

test('every change is written to the audit trail', function () {
    $ticket = clientTicket(Client::factory()->create());
    $staff = supportStaff([$ticket->department]);

    app(PostReply::class)->handle($ticket, TicketAuthor::staff($staff), 'Done.', status: TicketStatus::Closed);

    expect(TicketEvent::query()->where('ticket_id', $ticket->id)->orderBy('id')->pluck('type')->all())
        ->toBe(['opened', 'reply', 'closed'])
        ->and($ticket->fresh())->status->toBe(TicketStatus::Closed)->closed_by->value->toBe('staff');
});

test('first response minutes ignore autoresponses and notes', function () {
    $this->travelTo(now()->startOfMinute());
    $department = Department::factory()->create(['send_autoresponse' => true, 'autoresponse_message' => 'We got your message.']);
    $ticket = clientTicket(Client::factory()->create(), $department);
    $staff = supportStaff([$department]);

    $this->travel(10)->minutes();
    app(AddNote::class)->handle($ticket, $staff, 'Looking.');
    $this->travel(35)->minutes();
    app(PostReply::class)->handle($ticket, TicketAuthor::staff($staff), 'Fixed.');

    $ticket = Ticket::query()->findOrFail($ticket->id);
    expect((int) $ticket->created_at->diffInMinutes($ticket->first_staff_reply_at))->toBe(45)
        ->and($ticket->messages()->where('is_autoresponse', true)->count())->toBe(1);
});
