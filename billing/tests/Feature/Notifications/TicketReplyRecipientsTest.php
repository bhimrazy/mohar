<?php

use App\Models\User;
use App\Modules\Core\Models\Client;
use App\Modules\Notifications\Mail\TemplatedMail;
use App\Modules\Notifications\Models\RoleEmailSubscription;
use App\Modules\Support\Actions\MergeTickets;
use App\Modules\Support\Actions\PostReply;
use App\Modules\Support\Actions\SaveDepartment;
use App\Modules\Support\Actions\UpdateTicket;
use App\Modules\Support\Data\TicketAuthor;
use App\Modules\Support\Models\Department;
use App\Modules\Support\Models\Ticket;
use App\Modules\Support\Services\TicketAccess;
use Illuminate\Support\Facades\Mail;

require_once dirname(__DIR__).'/Support/helpers.php';
require_once __DIR__.'/helpers.php';

beforeEach(fn () => seedEmailTemplates());

// B1: guest identity is an unproven email. Someone who opened the older ticket under the victim's address keeps its
// link after the victim's own ticket is merged into it, and reads the victim's messages.
test('probe: a same-email guest merge leaves the older ticket key working on the merged thread', function () {
    $department = Department::factory()->create();
    $planted = guestTicket($department, 'victim@example.com');
    $victim = guestTicket($department, 'victim@example.com');
    $victim->messages()->first()->update(['body' => 'my private details']);
    $key = $planted->access_key;

    app(MergeTickets::class)->handle([$planted->id, $victim->id], User::factory()->staff()->create());

    $this->get(route('support.tickets.show', ['number' => $planted->number, 'key' => $key]))->assertNotFound();
});

// B2a: an assignee removed from the department keeps the assignment (SaveDepartment syncs members only) and is
// still mailed the customer's reply body, though TicketAccess no longer lets them open the ticket.
test('probe: an assignee without department access is not mailed the reply', function () {
    $department = Department::factory()->create();
    $agent = User::factory()->staff(['support.tickets.view', 'support.tickets.manage'])->create(['email' => 'agent@example.test']);
    $department->staff()->attach($agent->id, ['notify_new' => true, 'notify_replies' => true]);
    app(TicketAccess::class)->forget();
    $ticket = clientTicket(Client::factory()->create(), $department);
    app(UpdateTicket::class)->handle($ticket, $agent, ['assigned_to' => $agent->id]);
    $department->staff()->detach($agent->id);
    app(TicketAccess::class)->forget();
    expect(app(TicketAccess::class)->canAccess($agent->fresh(), $ticket->fresh()))->toBeFalse();
    Mail::fake();

    app(PostReply::class)->handle($ticket, TicketAuthor::client(User::query()->findOrFail($ticket->user_id)), 'secret reply body');

    Mail::assertNotSent(TemplatedMail::class, fn (TemplatedMail $mail): bool => $mail->hasTo('agent@example.test'));
});

// B2b: the fallback mails every support-alert subscriber with support.tickets.view, including staff scoped to other
// departments.
test('probe: the fallback does not mail a subscriber who cannot open the department', function () {
    $department = Department::factory()->create();
    $other = Department::factory()->create();
    $outsider = User::factory()->staff(['support.tickets.view'])->create(['email' => 'outsider@example.test']);
    $other->staff()->attach($outsider->id, ['notify_new' => true, 'notify_replies' => true]);
    RoleEmailSubscription::query()->create(['role_id' => $outsider->role_id, 'categories' => ['support']]);
    app(TicketAccess::class)->forget();
    Mail::fake();

    $ticket = clientTicket(Client::factory()->create(), $department);

    expect(app(TicketAccess::class)->canAccess($outsider->fresh(), $ticket))->toBeFalse();
    Mail::assertNotSent(TemplatedMail::class, fn (TemplatedMail $mail): bool => $mail->hasTo('outsider@example.test'));
});
