<?php

use App\Modules\Core\Models\Client;
use App\Modules\Support\Actions\PostReply;
use App\Modules\Support\Data\TicketAuthor;
use App\Modules\Support\Enums\TicketStatus;
use App\Modules\Support\Events\TicketMerged;
use App\Modules\Support\Models\Department;
use App\Modules\Support\Models\Ticket;
use App\Modules\Support\Models\TicketMessage;
use Illuminate\Support\Facades\Event;

require_once __DIR__.'/helpers.php';

test('newer tickets merge into the oldest with messages in chronological order', function () {
    Event::fake([TicketMerged::class]);
    $client = Client::factory()->create();
    $department = Department::factory()->create();
    $older = clientTicket($client, $department, 'First problem');
    $this->travel(5)->minutes();
    $newer = clientTicket($client, $department, 'Second problem');
    $this->travel(5)->minutes();
    $staff = supportStaff([$department]);
    app(PostReply::class)->handle($older, TicketAuthor::staff($staff), 'Reply to first');

    $this->post(route('admin.support.tickets.merge'), ['tickets' => [$newer->id, $older->id]])
        ->assertRedirect(route('admin.support.tickets.show', $older));

    $bodies = TicketMessage::query()->where('ticket_id', $older->id)->orderBy('created_at')->orderBy('id')->pluck('body')->all();
    expect($bodies[0])->toBe('First problem')
        ->and($bodies[1])->toBe('Second problem')
        ->and($bodies[2])->toStartWith('Reply to first')
        ->and($newer->fresh())->merged_into_id->toBe($older->id)->status->toBe(TicketStatus::Closed)
        ->and($older->fresh()->status)->toBe(TicketStatus::Answered);
    Event::assertDispatched(TicketMerged::class, fn (TicketMerged $event) => $event->targetId === $older->id && $event->sourceIds === [$newer->id]);

    $this->get(route('admin.support.tickets.show', $newer))->assertRedirect(route('admin.support.tickets.show', $older));
});

test('tickets of different clients merge only with the cross-client permission', function () {
    $department = Department::factory()->create();
    $a = clientTicket(Client::factory()->create(), $department);
    $b = clientTicket(Client::factory()->create(), $department);
    supportStaff([$department]);

    $this->post(route('admin.support.tickets.merge'), ['tickets' => [$a->id, $b->id]])->assertSessionHasErrors('tickets');
    expect(Ticket::query()->whereNotNull('merged_into_id')->count())->toBe(0);

    supportStaff([$department], ['support.tickets.view', 'support.tickets.manage', 'support.tickets.merge_cross_client']);
    $this->post(route('admin.support.tickets.merge'), ['tickets' => [$a->id, $b->id]])->assertRedirect();
    expect($b->fresh()->merged_into_id)->toBe($a->id);
});
