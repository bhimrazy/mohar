<?php

use App\Contracts\Support\TicketStaffDirectory;
use App\Models\User;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Support\NullTicketStaffDirectory;
use App\Modules\Notifications\Mail\TemplatedMail;
use App\Modules\Notifications\Services\RecipientResolver;
use App\Modules\Notifications\Services\TicketStaffRecipients;
use App\Modules\Support\Actions\PostReply;
use App\Modules\Support\Actions\UpdateTicket;
use App\Modules\Support\Data\TicketAuthor;
use App\Modules\Support\Models\Department;
use App\Modules\Support\Services\TicketAccess;
use Illuminate\Support\Facades\Mail;

require_once dirname(__DIR__).'/Support/helpers.php';
require_once __DIR__.'/helpers.php';

/*
 * Staff ticket mail goes to the department's members and the assignee (#30), not to every staff member with the
 * support alert category. The Support module answers through App\Contracts\Support\TicketStaffDirectory.
 */

/** @var list<string> subjects of the staff mails sent to an address */
function staffTicketSubjectsFor(string $address): array
{
    $subjects = [];

    Mail::assertSent(TemplatedMail::class, function (TemplatedMail $mail) use (&$subjects, $address): bool {
        if ($mail->hasTo($address)) {
            $subjects[] = $mail->subjectLine;
        }

        return true;
    });

    return $subjects;
}

/**
 * @return array{0: Department, 1: User, 2: User, 3: User}
 */
function departmentWithMembers(): array
{
    $department = Department::factory()->create();
    $listed = User::factory()->staff(['support.tickets.view'])->create(['email' => 'listed@example.test']);
    $quiet = User::factory()->staff(['support.tickets.view'])->create(['email' => 'quiet@example.test']);
    $department->staff()->attach($listed->id, ['notify_new' => true, 'notify_replies' => true]);
    $department->staff()->attach($quiet->id, ['notify_new' => false, 'notify_replies' => false]);
    app(TicketAccess::class)->forget();

    return [$department, $listed, $quiet, User::factory()->staff(['*'])->create(['email' => 'boss@example.test'])];
}

beforeEach(function () {
    seedEmailTemplates();
});

it('mails a new ticket to the department members who asked for it, not to other staff', function () {
    [$department] = departmentWithMembers();
    Mail::fake();

    $ticket = clientTicket(Client::factory()->create(), $department);

    expect(staffTicketSubjectsFor('listed@example.test'))->toBe(["[#{$ticket->number}] New ticket: Help needed"]);
    Mail::assertNotSent(TemplatedMail::class, fn (TemplatedMail $mail): bool => $mail->hasTo('quiet@example.test') || $mail->hasTo('boss@example.test'));
});

it('mails a customer reply to the assignee alone', function () {
    [$department, $listed, $quiet] = departmentWithMembers();
    $ticket = clientTicket(Client::factory()->create(), $department);
    $department->staff()->updateExistingPivot($quiet->id, ['notify_replies' => true]);
    app(UpdateTicket::class)->handle($ticket, $listed, ['assigned_to' => $quiet->id]);
    Mail::fake();

    app(PostReply::class)->handle($ticket, TicketAuthor::client(User::query()->findOrFail($ticket->user_id)), 'Any news?');

    expect(staffTicketSubjectsFor('quiet@example.test'))->toBe(["[#{$ticket->number}] Client reply: Help needed"]);
    Mail::assertNotSent(TemplatedMail::class, fn (TemplatedMail $mail): bool => $mail->hasTo('listed@example.test') || $mail->hasTo('boss@example.test'));
});

it('mails a customer reply on an unassigned ticket to the members who asked for replies', function () {
    [$department] = departmentWithMembers();
    $ticket = clientTicket(Client::factory()->create(), $department);
    Mail::fake();

    app(PostReply::class)->handle($ticket, TicketAuthor::client(User::query()->findOrFail($ticket->user_id)), 'Any news?');

    expect(staffTicketSubjectsFor('listed@example.test'))->toHaveCount(1);
    Mail::assertNotSent(TemplatedMail::class, fn (TemplatedMail $mail): bool => $mail->hasTo('quiet@example.test') || $mail->hasTo('boss@example.test'));
});

it('falls back to the support alert subscribers when the assignee cannot be mailed and the department has no members', function () {
    $department = Department::factory()->create();
    $boss = User::factory()->staff(['*'])->create(['email' => 'boss@example.test']);
    $ticket = clientTicket(Client::factory()->create(), $department);
    $ticket->forceFill(['assigned_to' => User::factory()->staff(['support.tickets.view'])->create(['is_active' => false])->id])->save();
    Mail::fake();

    app(PostReply::class)->handle($ticket, TicketAuthor::client(User::query()->findOrFail($ticket->user_id)), 'Any news?');

    expect(staffTicketSubjectsFor($boss->email))->toBe(["[#{$ticket->number}] Client reply: Help needed"]);
});

it('mails the new department when a ticket moves and nobody holds it, but nobody when it keeps its assignee', function () {
    [$from, $listed] = departmentWithMembers();
    $to = Department::factory()->create();
    $newcomer = User::factory()->staff(['support.tickets.view'])->create(['email' => 'newcomer@example.test']);
    $to->staff()->attach($newcomer->id);
    $to->staff()->attach($listed->id);
    app(TicketAccess::class)->forget();
    $moved = clientTicket(Client::factory()->create(), $from);
    $kept = clientTicket(Client::factory()->create(), $from);
    app(UpdateTicket::class)->handle($kept, $listed, ['assigned_to' => $listed->id]);
    Mail::fake();
    app(UpdateTicket::class)->handle($moved, $listed, ['department_id' => $to->id]);
    app(UpdateTicket::class)->handle($kept, $listed, ['department_id' => $to->id]);

    expect(staffTicketSubjectsFor('newcomer@example.test'))->toBe(["[#{$moved->number}] Moved to {$to->name}"]);
    Mail::assertNotSent(TemplatedMail::class, fn (TemplatedMail $mail): bool => str_contains($mail->subjectLine, "[#{$kept->number}] Moved"));
});

it('leaves the support alert subscribers as the recipients when no Support directory answers', function () {
    $boss = User::factory()->staff(['*'])->create(['email' => 'boss@example.test']);
    $service = new TicketStaffRecipients(new NullTicketStaffDirectory, app(RecipientResolver::class));

    expect(collect($service->forNewTicket(1))->pluck('email')->all())->toBe([$boss->email])
        ->and(collect($service->forCustomerReply(1))->pluck('email')->all())->toBe([$boss->email])
        ->and(app(TicketStaffDirectory::class))->not->toBeInstanceOf(NullTicketStaffDirectory::class);
});

it('answers for an unknown ticket with nothing', function () {
    $directory = app(TicketStaffDirectory::class);

    expect($directory->newTicketMembers(999))->toBe([])->and($directory->replyMembers(999))->toBe([])->and($directory->assigneeId(999))->toBeNull();
});
