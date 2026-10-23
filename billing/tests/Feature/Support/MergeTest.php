<?php

use App\Events\Support\TicketMerged;
use App\Models\User;
use App\Modules\Core\Models\Client;
use App\Modules\Notifications\Mail\TemplatedMail;
use App\Modules\Support\Actions\MergeTickets;
use App\Modules\Support\Actions\PostReply;
use App\Modules\Support\Data\TicketAuthor;
use App\Modules\Support\Enums\TicketStatus;
use App\Modules\Support\Models\Department;
use App\Modules\Support\Models\Ticket;
use App\Modules\Support\Models\TicketEvent;
use App\Modules\Support\Models\TicketMessage;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/helpers.php';
require_once dirname(__DIR__).'/Notifications/helpers.php';

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

test('guest tickets of different emails need the cross-client permission to merge', function () {
    $department = Department::factory()->create();
    $victim = guestTicket($department, 'victim@example.com');
    $attacker = guestTicket($department, 'attacker@example.com');
    supportStaff([$department]);

    $this->post(route('admin.support.tickets.merge'), ['tickets' => [$victim->id, $attacker->id]])->assertSessionHasErrors('tickets');

    expect($attacker->fresh()->merged_into_id)->toBeNull();
});

test('a merged guest link never reveals or grants access to the target ticket', function () {
    $department = Department::factory()->create();
    $victim = guestTicket($department, 'victim@example.com');
    $attacker = guestTicket($department, 'attacker@example.com');
    supportStaff([$department], ['support.tickets.view', 'support.tickets.manage', 'support.tickets.merge_cross_client']);
    $this->post(route('admin.support.tickets.merge'), ['tickets' => [$victim->id, $attacker->id]])->assertRedirect();
    auth()->logout();

    $response = $this->get(route('support.tickets.show', ['number' => $attacker->number, 'key' => $attacker->access_key]));

    $response->assertOk()->assertInertia(fn (Assert $page) => $page->component('public/support/tickets/merged')
        ->where('number', $attacker->number)
        ->missing('ticket')
        ->missing('accessKey'));
    expect($response->getContent())->not->toContain($victim->access_key)
        ->and($victim->fresh()->cc)->not->toContain('attacker@example.com');

    $this->post(route('support.tickets.reply', ['number' => $attacker->number, 'key' => $attacker->access_key]), ['body' => 'injected'])->assertNotFound();
    $this->get(route('support.tickets.show', ['number' => $attacker->number, 'key' => 'wrong-key']))->assertNotFound();
    expect(TicketMessage::query()->where('body', 'injected')->exists())->toBeFalse()
        ->and($attacker->fresh()->access_key)->not->toBe($attacker->access_key)
        ->and($victim->fresh()->access_key)->not->toBe($victim->access_key);
});

test('after a cross-client merge the source client cannot post into the target ticket', function () {
    [, $clientA] = actingAsClient();
    $department = Department::factory()->create();
    $target = clientTicket(Client::factory()->create(), $department);
    $source = clientTicket($clientA, $department);
    app(MergeTickets::class)->handle([$target->id, $source->id], User::factory()->staff()->create(), allowCrossClient: true);

    $this->get(route('client.support.tickets.show', $source->number))->assertNotFound();
    $this->post(route('client.support.tickets.reply', $source->number), ['body' => 'injected by A'])->assertNotFound();

    expect(TicketMessage::query()->where('body', 'injected by A')->exists())->toBeFalse();
});

test('a status change caused by a merge is audited', function () {
    $client = Client::factory()->create();
    $department = Department::factory()->create();
    $older = clientTicket($client, $department);
    $staff = supportStaff([$department]);
    app(PostReply::class)->handle($older, TicketAuthor::staff($staff), 'Answered');
    $this->travel(1)->minutes();
    $newer = clientTicket($client, $department);

    app(MergeTickets::class)->handle([$older->id, $newer->id], $staff);

    expect($older->fresh()->status)->toBe(TicketStatus::Open)
        ->and(TicketEvent::query()->where('ticket_id', $older->id)->where('type', 'status')->latest('id')->first()?->to)->toBe('open');
});

test('a cross-requester merge replaces the target guest key, locks out the old link and emails the new one to the target requester', function () {
    $department = Department::factory()->create();
    $target = guestTicket($department, 'victim@example.com');
    $source = guestTicket($department, 'other@example.com');
    $oldKey = $target->access_key;
    $staff = User::factory()->staff()->create();

    Mail::fake();
    seedEmailTemplates();
    app(MergeTickets::class)->handle([$target->id, $source->id], $staff, allowCrossClient: true);

    $target->refresh();
    expect($target->access_key)->not->toBe($oldKey)->toHaveLength(32);
    $this->get(route('support.tickets.show', ['number' => $target->number, 'key' => $oldKey]))->assertNotFound();
    $this->post(route('support.tickets.reply', ['number' => $target->number, 'key' => $oldKey]), ['body' => 'late'])->assertNotFound();
    $this->get(route('support.tickets.show', ['number' => $target->number, 'key' => $target->access_key]))->assertOk();
    expect(mailedTo('Question before buying'))->toBe(['victim@example.com']);
    Mail::assertSent(TemplatedMail::class, fn (TemplatedMail $mail): bool => str_contains((string) $mail->htmlBody, 'key='.$target->access_key)
        && ! str_contains((string) $mail->htmlBody, $oldKey));
});

test('a merge of tickets from one requester keeps the target key and sends no link', function () {
    $department = Department::factory()->create();
    $target = guestTicket($department, 'same@example.com');
    $source = guestTicket($department, 'SAME@example.com');
    $key = $target->access_key;

    Mail::fake();
    seedEmailTemplates();
    app(MergeTickets::class)->handle([$target->id, $source->id], User::factory()->staff()->create());

    expect($target->fresh()->access_key)->toBe($key);
    Mail::assertNothingSent();
});

test('a cross-requester merge into a client ticket replaces its key but emails nothing', function () {
    $department = Department::factory()->create();
    $target = clientTicket(Client::factory()->create(), $department);
    $source = guestTicket($department, 'other@example.com');
    $key = $target->access_key;

    Mail::fake();
    seedEmailTemplates();
    app(MergeTickets::class)->handle([$target->id, $source->id], User::factory()->staff()->create(), allowCrossClient: true);

    expect($target->fresh()->access_key)->not->toBe($key);
    Mail::assertNothingSent();
});
