<?php

use App\Events\Support\InboundEmailBounced;
use App\Events\Support\TicketOpened;
use App\Models\User;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Support\Settings;
use App\Modules\Support\Actions\MergeTickets;
use App\Modules\Support\Actions\PostReply;
use App\Modules\Support\Data\TicketAuthor;
use App\Modules\Support\Drivers\Inbound\GenericDriver;
use App\Modules\Support\Enums\AuthorType;
use App\Modules\Support\Enums\TicketStatus;
use App\Modules\Support\Models\Department;
use App\Modules\Support\Models\InboundEmail;
use App\Modules\Support\Models\SpamRule;
use App\Modules\Support\Models\Ticket;
use App\Modules\Support\Models\TicketMessage;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    Storage::fake('objects');
    app(Settings::class)->set('support.inbound_secret', 'test-secret');
    $this->department = Department::factory()->create(['email' => 'help@support.example.test']);
});

/**
 * @param  array<string, mixed>  $overrides
 */
function inboundEmail(array $overrides = [], string $secret = 'test-secret'): TestResponse
{
    $payload = [
        'message_id' => '<'.uniqid('m', true).'@mail.example>',
        'from' => 'Casey Customer <casey@example.com>',
        'to' => ['help@support.example.test'],
        'subject' => 'Server is slow',
        'text' => "The server is slow since this morning.\n\nOn Mon, Oct 12, 2026 at 9:00 Support wrote:\n> old quoted text",
        'headers' => [],
        ...$overrides,
    ];
    $body = (string) json_encode($payload);

    return test()->call('POST', route('support.inbound.email', 'generic'), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_SUPPORT_SIGNATURE' => GenericDriver::sign($body, $secret),
    ], $body);
}

test('a new email opens a ticket in the department of the recipient address, without quoted history', function () {
    Event::fake([TicketOpened::class]);

    inboundEmail()->assertCreated()->assertJsonPath('status', 'ticket_created');

    $ticket = Ticket::query()->sole();
    expect($ticket)->department_id->toBe($this->department->id)->status->toBe(TicketStatus::Open)
        ->source->value->toBe('email')->requester_email->toBe('casey@example.com')
        ->and($ticket->messages()->firstOrFail()->body)->toBe('The server is slow since this morning.');
    Event::assertDispatched(TicketOpened::class, fn (TicketOpened $event) => ! $event->byStaff);
});

test('the webhook rejects bad signatures, unknown providers and a missing secret', function () {
    inboundEmail([], 'wrong-secret')->assertUnauthorized();

    $this->postJson(route('support.inbound.email', 'mailgun'), [])->assertNotFound();

    app(Settings::class)->set('support.inbound_secret', '');
    inboundEmail([], '')->assertUnauthorized();

    expect(Ticket::query()->count())->toBe(0);
});

test('auto-submitted mail is dropped and logged', function () {
    inboundEmail(['headers' => ['Auto-Submitted' => 'auto-replied']])->assertAccepted()->assertJsonPath('status', 'rejected_auto_submitted');
    inboundEmail(['headers' => ['Precedence' => 'bulk']])->assertJsonPath('status', 'rejected_auto_submitted');
    inboundEmail(['from' => 'MAILER-DAEMON@mx.example.com'])->assertJsonPath('status', 'rejected_auto_submitted');

    expect(Ticket::query()->count())->toBe(0)
        ->and(InboundEmail::query()->where('status', 'rejected_auto_submitted')->count())->toBe(3);
});

test('mail from one of our own department addresses is dropped as a loop', function () {
    inboundEmail(['from' => 'help@support.example.test'])->assertJsonPath('status', 'rejected_loop');

    expect(Ticket::query()->count())->toBe(0);
});

test('the 11th email from a sender within 15 minutes is rate limited', function () {
    foreach (range(1, 10) as $i) {
        inboundEmail(['subject' => "Message {$i}"])->assertJsonPath('status', 'ticket_created');
    }

    inboundEmail(['subject' => 'Message 11'])->assertJsonPath('status', 'rejected_rate_limit');

    $this->travel(16)->minutes();
    inboundEmail(['subject' => 'Later'])->assertJsonPath('status', 'ticket_created');
});

test('spam rules drop matching mail case-insensitively', function () {
    SpamRule::query()->create(['field' => 'subject', 'match' => 'contains', 'value' => 'cheap pills']);

    inboundEmail(['subject' => 'CHEAP PILLS here'])->assertJsonPath('status', 'rejected_spam');

    expect(Ticket::query()->count())->toBe(0);
});

test('a reply whose In-Reply-To matches a stored message id is appended as customer_reply', function () {
    $client = Client::factory()->create(['email' => 'casey@example.com']);
    $ticket = clientTicket($client, $this->department);
    $staff = supportStaff([$this->department]);
    $staffReply = app(PostReply::class)->handle($ticket, TicketAuthor::staff($staff), 'Can you share logs?');

    inboundEmail(['subject' => 'Re: something unrelated', 'in_reply_to' => $staffReply->email_message_id, 'text' => 'Here are the logs.'])
        ->assertJsonPath('status', 'reply_added');

    $latest = TicketMessage::query()->where('ticket_id', $ticket->id)->latest('id')->firstOrFail();
    expect($ticket->fresh()->status)->toBe(TicketStatus::CustomerReply)
        ->and($latest)->body->toBe('Here are the logs.')->author_type->toBe(AuthorType::Client)->source->value->toBe('email');
});

test('a [#number] subject token routes the reply but holds it until it is threaded on our mail', function () {
    $ticket = clientTicket(Client::factory()->create(['email' => 'casey@example.com']), $this->department);

    inboundEmail(['subject' => "Re: [#{$ticket->number}] Help needed"])->assertJsonPath('status', 'blocked');
    expect(InboundEmail::query()->where('status', 'blocked')->sole()->ticket_id)->toBe($ticket->id)
        ->and($ticket->messages()->count())->toBe(1);

    inboundEmail(['subject' => "Re: [#{$ticket->number}] Help needed", 'references' => ['<unrelated@x>', $ticket->messages()->firstOrFail()->email_message_id]])
        ->assertJsonPath('status', 'reply_added');
    expect($ticket->messages()->count())->toBe(2);
});

test('a reply from an unrelated sender is blocked until staff import it', function () {
    $ticket = clientTicket(Client::factory()->create(), $this->department);

    inboundEmail(['from' => 'stranger@evil.example', 'subject' => "Re: [#{$ticket->number}]", 'text' => 'Spoofed'])
        ->assertJsonPath('status', 'blocked');

    expect($ticket->messages()->count())->toBe(1);
    $blocked = InboundEmail::query()->where('status', 'blocked')->sole();
    expect($blocked->ticket_id)->toBe($ticket->id);

    supportStaff([$this->department]);
    $this->post(route('admin.support.tickets.inbound.import', [$ticket, $blocked]))->assertRedirect();

    $imported = $ticket->messages()->reorder()->latest('id')->firstOrFail();
    expect($imported)->body->toBe('Spoofed')->is_unverified_sender->toBeTrue()
        ->and($blocked->fresh()->status->value)->toBe('imported');
});

test('unverified replies can be accepted by setting with a badge', function () {
    app(Settings::class)->set('support.allow_unverified_email_replies', true);
    $ticket = clientTicket(Client::factory()->create(), $this->department);

    inboundEmail(['from' => 'other@example.org', 'subject' => "[#{$ticket->number}]"])->assertJsonPath('status', 'reply_added');

    expect($ticket->messages()->reorder()->latest('id')->firstOrFail()->is_unverified_sender)->toBeTrue();
});

test('the same Message-ID posted twice creates only one message', function () {
    inboundEmail(['message_id' => '<dup@mail.example>'])->assertCreated();
    inboundEmail(['message_id' => '<dup@mail.example>'])->assertOk()->assertJsonPath('duplicate', true);

    expect(Ticket::query()->count())->toBe(1)
        ->and(TicketMessage::query()->count())->toBe(1)
        ->and(InboundEmail::query()->count())->toBe(1);
});

test('mail without a Message-ID is deduplicated by from, date and subject', function () {
    inboundEmail(['message_id' => null, 'date' => 'Mon, 12 Oct 2026 09:00:00 +0000']);
    inboundEmail(['message_id' => null, 'date' => 'Mon, 12 Oct 2026 09:00:00 +0000']);

    expect(Ticket::query()->count())->toBe(1);
});

test('departments that accept replies only bounce new emails', function () {
    Event::fake([InboundEmailBounced::class]);
    $this->department->update(['replies_only_by_email' => true]);

    inboundEmail()->assertJsonPath('status', 'bounced_replies_only');

    expect(Ticket::query()->count())->toBe(0);
    Event::assertDispatched(InboundEmailBounced::class, fn (InboundEmailBounced $event) => $event->template === 'ticket.replies_only_bounce' && $event->recipient === 'casey@example.com');
});

test('clients-only departments bounce unknown senders', function () {
    Event::fake([InboundEmailBounced::class]);
    $this->department->update(['clients_only' => true]);

    inboundEmail()->assertJsonPath('status', 'bounced_unregistered');
    Client::factory()->create(['email' => 'casey@example.com']);
    inboundEmail()->assertJsonPath('status', 'ticket_created');

    Event::assertDispatched(InboundEmailBounced::class, fn (InboundEmailBounced $event) => $event->template === 'ticket.unregistered_bounce');
});

test('email replies reopen closed tickets unless prevented', function () {
    Event::fake([InboundEmailBounced::class]);
    $ticket = clientTicket(Client::factory()->create(['email' => 'casey@example.com']), $this->department);
    $ticket->forceFill(['status' => TicketStatus::Closed, 'closed_at' => now()])->save();

    $ours = $ticket->messages()->firstOrFail()->email_message_id;
    inboundEmail(['subject' => "[#{$ticket->number}]", 'in_reply_to' => $ours])->assertJsonPath('status', 'reply_added');
    expect($ticket->fresh()->status)->toBe(TicketStatus::CustomerReply);

    $ticket->fresh()->forceFill(['status' => TicketStatus::Closed, 'closed_at' => now()])->save();
    app(Settings::class)->set('support.prevent_email_reopen', true);
    inboundEmail(['subject' => "[#{$ticket->number}] again", 'in_reply_to' => $ours])->assertJsonPath('status', 'bounced_closed');
    expect($ticket->fresh()->status)->toBe(TicketStatus::Closed);
    Event::assertDispatched(InboundEmailBounced::class, fn (InboundEmailBounced $event) => $event->template === 'ticket.closed_bounce');
});

test('unknown recipients use the default department or are rejected', function () {
    inboundEmail(['to' => ['nobody@elsewhere.test']])->assertJsonPath('status', 'rejected_no_department');

    $fallback = Department::factory()->create();
    app(Settings::class)->set('support.default_department_id', $fallback->id);
    inboundEmail(['to' => ['nobody@elsewhere.test'], 'subject' => 'Second'])->assertJsonPath('status', 'ticket_created');

    expect(Ticket::query()->sole()->department_id)->toBe($fallback->id);
});

test('inbound replies to a merged ticket land in the target', function () {
    $client = Client::factory()->create(['email' => 'casey@example.com']);
    $older = clientTicket($client, $this->department);
    $newer = clientTicket($client, $this->department);
    app(MergeTickets::class)->handle([$older->id, $newer->id], User::factory()->staff()->create());

    $newerMessageId = TicketMessage::query()->where('body', 'My site is down.')->orderByDesc('id')->value('email_message_id');
    inboundEmail(['subject' => "Re: [#{$newer->number}]", 'text' => 'Following up', 'in_reply_to' => $newerMessageId])->assertJsonPath('status', 'reply_added');

    expect(TicketMessage::query()->where('ticket_id', $older->id)->latest('id')->value('body'))->toBe('Following up');
});

test('rejected attachments are listed in a system note; accepted ones are stored', function () {
    inboundEmail(['attachments' => [
        ['filename' => 'notes.txt', 'content' => base64_encode("plain text\n")],
        ['filename' => 'evil.php', 'content' => base64_encode('<?php echo 1;')],
    ]])->assertJsonPath('status', 'ticket_created');

    $ticket = Ticket::query()->sole();
    expect($ticket->attachments()->pluck('original_name')->all())->toBe(['notes.txt'])
        ->and($ticket->messages()->where('type', 'note')->value('body'))->toContain('evil.php');
});

test('Cc addresses are added to the ticket when enabled', function () {
    app(Settings::class)->set('support.auto_add_cc', true);

    inboundEmail(['cc' => ['Boss@Example.com', 'help@support.example.test']]);

    expect(Ticket::query()->sole()->cc)->toBe(['boss@example.com']);
});

test('overlong subjects are truncated before storage', function () {
    inboundEmail(['subject' => str_repeat('a', 400)])->assertJsonPath('status', 'ticket_created');

    expect(mb_strlen(Ticket::query()->sole()->subject))->toBeLessThanOrEqual(255);
});

test('spoofed replies never post as the client', function (array $payload) {
    $client = Client::factory()->create(['email' => 'victim@example.com']);
    $ticket = clientTicket($client, $this->department);
    $ours = $ticket->messages()->firstOrFail()->email_message_id;
    TicketMessage::factory()->for($ticket)->create(['email_message_id' => '<customer-chosen@victim.example>', 'source' => 'email', 'body' => 'Earlier email']);
    $payload = array_map(fn ($value) => is_string($value) ? str_replace(['{number}', '{ours}'], [$ticket->number, $ours], $value) : $value, $payload);

    inboundEmail(['text' => 'Spoofed', ...$payload])->assertAccepted()->assertJsonPath('status', 'blocked');

    expect($ticket->messages()->count())->toBe(2)
        ->and(TicketMessage::query()->where('body', 'Spoofed')->exists())->toBeFalse();
})->with([
    'forged Authentication-Results pass' => [['from' => 'victim@example.com', 'subject' => 'Re: [#{number}]', 'headers' => ['Authentication-Results' => 'mx.example; spf=pass dkim=pass header.d=example.com; dmarc=pass']]],
    'unaligned spf/dkim pass with dmarc fail' => [['from' => 'victim@example.com', 'subject' => 'Re: [#{number}]', 'auth' => ['spf' => 'pass', 'dkim' => 'pass', 'dmarc' => 'fail'], 'headers' => ['Authentication-Results' => 'mx; spf=pass smtp.mailfrom=evil.example; dkim=pass header.d=evil.example; dmarc=fail']]],
    'envelope local-part trick (threaded)' => [['from' => '"victim@example.com" <victim@example.com.evil.example>', 'in_reply_to' => '{ours}']],
    'plus-address trick (threaded)' => [['from' => 'victim+x@example.com', 'in_reply_to' => '{ours}']],
    'no threading at all' => [['from' => 'victim@example.com', 'subject' => 'Re: [#{number}]']],
    'threaded on a non-generated id' => [['from' => 'victim@example.com', 'in_reply_to' => '<customer-chosen@victim.example>', 'subject' => 'Re: [#{number}]']],
]);

test('a reply threaded on our Message-ID from the exact client address is verified', function () {
    $client = Client::factory()->create(['email' => 'victim@example.com']);
    $ticket = clientTicket($client, $this->department);

    inboundEmail(['from' => 'Victim <VICTIM@Example.com>', 'in_reply_to' => $ticket->messages()->firstOrFail()->email_message_id, 'text' => 'Real reply'])
        ->assertCreated()->assertJsonPath('status', 'reply_added');

    expect(TicketMessage::query()->where('body', 'Real reply')->sole()->is_unverified_sender)->toBeFalse();
});

test('an inbound Message-ID equal to one of our outbound ids is dropped as a loop, not a 500', function () {
    $ticket = clientTicket(Client::factory()->create(['email' => 'casey@example.com']), $this->department);
    $outbound = $ticket->messages()->firstOrFail()->email_message_id;

    inboundEmail(['message_id' => $outbound])->assertAccepted()->assertJsonPath('status', 'rejected_loop');

    expect(Ticket::query()->count())->toBe(1);
});

test('a processing failure is recorded and the provider retry processes the mail again', function () {
    $calls = 0;
    Ticket::creating(function () use (&$calls) {
        if (++$calls === 1) {
            throw new RuntimeException('database hiccup');
        }
    });

    $this->withoutExceptionHandling();
    expect(fn () => inboundEmail(['message_id' => '<retry@mail.example>']))->toThrow(RuntimeException::class);
    expect(InboundEmail::query()->sole()->status->value)->toBe('failed');

    inboundEmail(['message_id' => '<retry@mail.example>'])->assertOk()->assertJsonPath('status', 'ticket_created');
    expect(Ticket::query()->count())->toBe(1);
});

test('loop and list headers are dropped', function (array $headers) {
    inboundEmail(['headers' => $headers])->assertJsonPath('status', 'rejected_auto_submitted');
})->with([
    'X-Autoreply' => [['X-Autoreply' => 'yes']],
    'List-Id' => [['List-Id' => '<news.example.com>']],
    'empty Return-Path' => [['Return-Path' => '<>']],
]);

test('oversized webhook bodies are refused before parsing', function () {
    app(Settings::class)->set('support.attachment_max_files', 1);
    app(Settings::class)->set('support.attachment_max_mb', 1);

    inboundEmail(['text' => str_repeat('a', 4 * 1024 * 1024)])->assertStatus(413);

    expect(InboundEmail::query()->count())->toBe(0);
});

test('a sender without a display name gets the name of the matching login', function () {
    $client = Client::factory()->create(['email' => 'casey@example.com']);
    $client->users()->attach(User::factory()->create(['email' => 'casey@example.com', 'name' => 'Casey Jones']), ['is_owner' => true]);

    inboundEmail(['from' => 'casey@example.com']);

    expect(Ticket::query()->sole()->requester_name)->toBe('Casey Jones')
        ->and($client->exists)->toBeTrue();
});
