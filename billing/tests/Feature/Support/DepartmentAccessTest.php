<?php

use App\Events\Support\TicketAssigned;
use App\Events\Support\TicketUnassigned;
use App\Models\User;
use App\Modules\Core\Models\Client;
use App\Modules\Support\Models\Department;
use App\Support\Search\GlobalSearch;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/helpers.php';

test('staff outside a department get 403 on its tickets and cannot be assigned them', function () {
    $billing = Department::factory()->create();
    $tech = Department::factory()->create();
    $ticket = clientTicket(Client::factory()->create(), $tech);
    $outsider = User::factory()->staff(['support.tickets.view', 'support.tickets.reply'])->create();
    $outsider->getConnection()->table('support_department_user')->insert(['department_id' => $billing->id, 'user_id' => $outsider->id]);

    $this->actingAs($outsider);
    $this->get(route('admin.support.tickets.show', $ticket))->assertForbidden();
    $this->post(route('admin.support.tickets.replies.store', $ticket), ['body' => 'Sneaky'])->assertForbidden();

    supportStaff([$tech]);
    $this->patch(route('admin.support.tickets.update', $ticket), ['assigned_to' => $outsider->id])->assertSessionHasErrors('assigned_to');
    expect($ticket->fresh()->assigned_to)->toBeNull();
});

test('access_all staff see every department', function () {
    $ticket = clientTicket(Client::factory()->create());
    supportStaff([], ['support.tickets.view', 'support.tickets.access_all']);

    $this->get(route('admin.support.tickets.show', $ticket))->assertOk();
});

test('the queue only lists tickets of the staff member\'s departments', function () {
    $mine = Department::factory()->create();
    $theirs = Department::factory()->create();
    $visible = clientTicket(Client::factory()->create(), $mine);
    clientTicket(Client::factory()->create(), $theirs);
    supportStaff([$mine]);

    $this->get(route('admin.support.tickets.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('admin/support/tickets/index')
            ->has('tickets.data', 1)
            ->where('tickets.data.0.id', $visible->id)
            ->has('departments', 1));
});

test('global search only returns tickets the staff member may open', function () {
    $mine = Department::factory()->create();
    $theirs = Department::factory()->create();
    $visible = clientTicket(Client::factory()->create(), $mine);
    $hidden = clientTicket(Client::factory()->create(), $theirs);
    $staff = supportStaff([$mine]);

    $groups = app(GlobalSearch::class)->search('Help', $staff);
    $tickets = collect($groups)->firstWhere('group', 'Tickets');

    expect(collect($tickets['results'])->pluck('href')->all())->toBe([route('admin.support.tickets.show', $visible)])
        ->and($hidden->id)->not->toBe($visible->id);
});

test('assigning to an eligible colleague emits TicketAssigned, self-assign does not', function () {
    Event::fake([TicketAssigned::class]);
    $department = Department::factory()->create();
    $ticket = clientTicket(Client::factory()->create(), $department);
    $colleague = User::factory()->staff()->create();
    $department->staff()->attach($colleague->id);
    $me = supportStaff([$department]);

    $this->patch(route('admin.support.tickets.update', $ticket), ['assigned_to' => $me->id])->assertRedirect();
    Event::assertNotDispatched(TicketAssigned::class);

    $this->patch(route('admin.support.tickets.update', $ticket), ['assigned_to' => $colleague->id])->assertRedirect();
    Event::assertDispatched(TicketAssigned::class, fn (TicketAssigned $event) => $event->staffUserId === $colleague->id);
});

test('moving department keeps an eligible assignee and unassigns an ineligible one', function () {
    Event::fake([TicketUnassigned::class]);
    $from = Department::factory()->create();
    $to = Department::factory()->create(['prevent_client_close' => true]);
    $ticket = clientTicket(Client::factory()->create(), $from);
    $assignee = User::factory()->staff(['support.tickets.view'])->create();
    $from->staff()->attach($assignee->id);
    $ticket->forceFill(['assigned_to' => $assignee->id])->save();
    supportStaff([$from, $to]);

    $this->patch(route('admin.support.tickets.update', $ticket), ['department_id' => $to->id])->assertRedirect();

    expect($ticket->fresh())->department_id->toBe($to->id)->assigned_to->toBeNull()->prevent_client_close->toBeTrue();
    Event::assertDispatched(TicketUnassigned::class, fn (TicketUnassigned $event) => $event->previousStaffUserId === $assignee->id);
});

test('staff without the view permission cannot open the queue', function () {
    $this->actingAs(User::factory()->staff(['billing.*'])->create());

    $this->get(route('admin.support.tickets.index'))->assertForbidden();
});
