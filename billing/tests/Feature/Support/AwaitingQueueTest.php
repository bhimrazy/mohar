<?php

use App\Models\User;
use App\Modules\Core\Models\Client;
use App\Modules\Notifications\Models\EmailTemplate;
use App\Modules\Support\Actions\AddNote;
use App\Modules\Support\Actions\EditMessage;
use App\Modules\Support\Actions\MergeTickets;
use App\Modules\Support\Actions\PostReply;
use App\Modules\Support\Data\TicketAuthor;
use App\Modules\Support\Enums\TicketStatus;
use App\Modules\Support\Models\Department;
use App\Modules\Support\Models\Ticket;
use App\Modules\Support\Models\TicketMessage;
use Carbon\CarbonImmutable;

require_once __DIR__.'/helpers.php';

/*
 * Awaiting reply queue (#51): Ticket::first_unanswered_at is the oldest customer reply staff have not answered,
 * kept on the ticket so the queue sorts by support_tickets_awaiting_queue_index (spec §13).
 */

test('repeated customer replies keep the first unanswered time, a staff reply clears it, the next customer reply starts it again', function () {
    $department = Department::factory()->create();
    $client = Client::factory()->create();
    $ticket = clientTicket($client, $department);
    $opened = $ticket->first_unanswered_at;
    $customer = TicketAuthor::client(User::query()->findOrFail($ticket->user_id));
    $staff = User::factory()->staff()->create();

    expect($opened)->not->toBeNull();

    $this->travel(5)->minutes();
    app(PostReply::class)->handle($ticket, $customer, 'Any news?');
    expect($ticket->fresh()->first_unanswered_at->equalTo($opened))->toBeTrue();

    $this->travel(5)->minutes();
    app(PostReply::class)->handle($ticket, TicketAuthor::staff($staff), 'Looking now.');
    expect($ticket->fresh()->first_unanswered_at)->toBeNull();

    $this->travel(5)->minutes();
    app(PostReply::class)->handle($ticket, $customer, 'Still down.');
    $second = $ticket->fresh();
    expect($second->first_unanswered_at)->not->toBeNull()
        ->and($second->first_unanswered_at->greaterThan($opened))->toBeTrue()
        ->and($second->first_unanswered_at->equalTo($second->last_client_reply_at))->toBeTrue();
});

test('an internal note does not answer the customer', function () {
    $ticket = clientTicket(Client::factory()->create());
    $staff = User::factory()->staff()->create();

    app(AddNote::class)->handle($ticket, $staff, 'Checking logs');

    expect($ticket->fresh()->first_unanswered_at)->not->toBeNull();
});

test('the queue lists pinned tickets, then the oldest unanswered, then answered ones, newest id first', function () {
    $department = Department::factory()->create();
    $staffUser = User::factory()->staff()->create();
    $oldest = clientTicket(Client::factory()->create(), $department);
    $this->travel(10)->minutes();
    $answered = clientTicket(Client::factory()->create(), $department);
    app(PostReply::class)->handle($answered, TicketAuthor::staff($staffUser), 'Done?', status: TicketStatus::Open);
    $this->travel(10)->minutes();
    $newest = clientTicket(Client::factory()->create(), $department);
    $this->travel(10)->minutes();
    $pinned = clientTicket(Client::factory()->create(), $department);
    $pinned->forceFill(['is_pinned' => true])->save();
    $answeredToo = clientTicket(Client::factory()->create(), $department);
    app(PostReply::class)->handle($answeredToo, TicketAuthor::staff($staffUser), 'Done?', status: TicketStatus::Open);

    $order = Ticket::query()->whereIn('status', ['open', 'customer_reply'])
        ->orderByDesc('is_pinned')->orderByOldestUnanswered()->orderByDesc('id')->pluck('id')->all();

    expect($order)->toBe([$pinned->id, $oldest->id, $newest->id, $answeredToo->id, $answered->id]);
});

test('a merge recomputes the first unanswered time from the combined thread', function () {
    $department = Department::factory()->create();
    $client = Client::factory()->create();
    $older = clientTicket($client, $department);
    $staff = User::factory()->staff()->create();
    $this->travel(5)->minutes();
    app(PostReply::class)->handle($older, TicketAuthor::staff($staff), 'On it.');
    $this->travel(5)->minutes();
    $newer = clientTicket($client, $department);
    $newerOpened = $newer->fresh()->first_unanswered_at;

    $target = app(MergeTickets::class)->handle([$older->id, $newer->id], $staff);

    expect($target->id)->toBe($older->id)
        ->and($target->fresh()->first_unanswered_at->equalTo($newerOpened))->toBeTrue();
});

test('deleting the oldest unanswered reply moves the time to the next one', function () {
    $ticket = clientTicket(Client::factory()->create());
    $customer = TicketAuthor::client(User::query()->findOrFail($ticket->user_id));
    $staff = User::factory()->staff()->create();
    $this->travel(5)->minutes();
    app(PostReply::class)->handle($ticket, TicketAuthor::staff($staff), 'Hello');
    $this->travel(5)->minutes();
    $first = app(PostReply::class)->handle($ticket, $customer, 'First question');
    $this->travel(5)->minutes();
    $second = app(PostReply::class)->handle($ticket, $customer, 'Second question');
    expect($ticket->fresh()->first_unanswered_at->equalTo($first->created_at))->toBeTrue();

    app(EditMessage::class)->delete($first, $staff);

    expect($ticket->fresh()->first_unanswered_at->equalTo($second->created_at))->toBeTrue();
});

test('the backfill fills tickets from their threads and leaves answered tickets empty', function () {
    $department = Department::factory()->create();
    $staff = User::factory()->staff()->create();
    $waiting = clientTicket(Client::factory()->create(), $department);
    $answered = clientTicket(Client::factory()->create(), $department);
    app(PostReply::class)->handle($answered, TicketAuthor::staff($staff), 'Done.');
    $expected = TicketMessage::query()->where('ticket_id', $waiting->id)->min('created_at');
    Ticket::query()->update(['first_unanswered_at' => null]);

    (require base_path('app/Modules/Support/Database/Migrations/2026_10_24_600800_add_first_unanswered_at_to_support_tickets.php'))->backfill();

    expect($waiting->fresh()->first_unanswered_at->equalTo(CarbonImmutable::parse($expected)))->toBeTrue()
        ->and($answered->fresh()->first_unanswered_at)->toBeNull();
});

test('support:recompute-unanswered repairs rows changed since a time and leaves older ones', function () {
    $department = Department::factory()->create();
    $staff = User::factory()->staff()->create();
    $stale = clientTicket(Client::factory()->create(), $department);
    $old = clientTicket(Client::factory()->create(), $department);
    $expected = $stale->fresh()->first_unanswered_at;
    // The previous release answered the ticket without clearing the column.
    app(PostReply::class)->handle($old, TicketAuthor::staff($staff), 'Done.');
    $old->forceFill(['first_unanswered_at' => now()->subDay()])->saveQuietly();
    Ticket::query()->whereKey($old->id)->update(['updated_at' => now()->subDays(2)]);
    Ticket::query()->whereKey($stale->id)->update(['first_unanswered_at' => now()->addDay(), 'updated_at' => now()]);

    $this->artisan('support:recompute-unanswered', ['--since' => now()->subHour()->toDateTimeString()])->assertSuccessful();

    expect($stale->fresh()->first_unanswered_at->equalTo($expected))->toBeTrue()
        ->and($old->fresh()->first_unanswered_at)->not->toBeNull();
});

test('support:recompute-unanswered reads a --since with a time-zone offset as that instant', function () {
    $stale = clientTicket(Client::factory()->create(), Department::factory()->create());
    $expected = $stale->fresh()->first_unanswered_at;
    Ticket::query()->whereKey($stale->id)->update(['first_unanswered_at' => now()->addDay(), 'updated_at' => now()]);

    // An hour ago in Nepal time: as a wall-clock time without the offset it would lie 5h45 in the future.
    $this->artisan('support:recompute-unanswered', ['--since' => now()->subHour()->setTimezone('Asia/Kathmandu')->toIso8601String()])->assertSuccessful();

    expect($stale->fresh()->first_unanswered_at->equalTo($expected))->toBeTrue();
});

test('the link-replaced mail cannot be switched off', function () {
    expect((new EmailTemplate(['key' => 'ticket.link_replaced']))->isAlwaysSent())->toBeTrue();
});
