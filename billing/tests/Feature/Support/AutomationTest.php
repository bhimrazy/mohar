<?php

use App\Events\Support\TicketClosed;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Support\Settings;
use App\Modules\Support\Actions\AutoCloseTickets;
use App\Modules\Support\Actions\PostReply;
use App\Modules\Support\Actions\PruneAttachments;
use App\Modules\Support\Actions\PurgeInboundRaw;
use App\Modules\Support\Data\TicketAuthor;
use App\Modules\Support\Enums\TicketStatus;
use App\Modules\Support\Models\InboundEmail;
use App\Modules\Support\Models\TicketAttachment;
use App\Modules\Support\Services\IncomingFile;
use App\Support\Automation\Automation;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/helpers.php';

test('idle answered tickets auto-close with closedBy=system; customer_reply tickets stay open', function () {
    Event::fake([TicketClosed::class]);
    $client = Client::factory()->create();
    $answered = clientTicket($client);
    $waiting = clientTicket($client);
    $staff = supportStaff([$answered->department, $waiting->department]);
    app(PostReply::class)->handle($answered, TicketAuthor::staff($staff), 'Done, please confirm.');
    $waiting->forceFill(['status' => TicketStatus::CustomerReply])->save();

    $this->travel(71)->hours();
    expect(app(AutoCloseTickets::class)->handle())->toBe(0);

    $this->travel(2)->hours();
    expect(app(AutoCloseTickets::class)->handle())->toBe(1)
        ->and(app(AutoCloseTickets::class)->handle())->toBe(0);

    expect($answered->fresh())->status->toBe(TicketStatus::Closed)->closed_by->value->toBe('system')
        ->and($waiting->fresh()->status)->toBe(TicketStatus::CustomerReply);
    Event::assertDispatchedTimes(TicketClosed::class, 1);
    Event::assertDispatched(TicketClosed::class, fn (TicketClosed $event) => $event->ticketId === $answered->id && $event->closedBy === 'system');
});

test('auto-close is off when hours is zero', function () {
    app(Settings::class)->set('support.auto_close_hours', 0);
    $ticket = clientTicket(Client::factory()->create());
    $ticket->forceFill(['status' => TicketStatus::Answered, 'last_reply_at' => now()->subYear()])->save();

    expect(app(AutoCloseTickets::class)->handle())->toBe(0);
});

test('the auto-close task runs in the housekeeping stage and through its command', function () {
    $ticket = clientTicket(Client::factory()->create());
    $ticket->forceFill(['status' => TicketStatus::Answered, 'last_reply_at' => now()->subDays(5)])->save();

    expect(app(Automation::class)->ordered()['support.auto_close']['stage'])->toBe(Automation::HOUSEKEEPING);

    $this->artisan('automation:run', ['--only' => 'support.auto_close'])->assertSuccessful();
    expect($ticket->fresh()->status)->toBe(TicketStatus::Closed);

    $this->artisan('support:auto-close')->assertSuccessful();
});

test('raw inbound payloads are purged after the retention window', function () {
    Storage::fake('objects');
    Storage::disk('objects')->put('support/inbound/old.json', '{}');
    $old = InboundEmail::query()->create(['provider' => 'generic', 'message_id' => '<old@x>', 'from' => 'a@b.c', 'status' => 'ticket_created', 'raw_path' => 'support/inbound/old.json', 'received_at' => now()->subDays(31)]);
    $fresh = InboundEmail::query()->create(['provider' => 'generic', 'message_id' => '<new@x>', 'from' => 'a@b.c', 'status' => 'ticket_created', 'raw_path' => 'support/inbound/new.json', 'received_at' => now()]);

    expect(app(PurgeInboundRaw::class)->handle())->toBe(1)
        ->and(app(PurgeInboundRaw::class)->handle())->toBe(0)
        ->and($old->fresh()->raw_path)->toBeNull()
        ->and($fresh->fresh()->raw_path)->not->toBeNull();
    Storage::disk('objects')->assertMissing('support/inbound/old.json');
});

test('attachments of long-closed tickets are pruned when enabled, keeping metadata', function () {
    Storage::fake('objects');
    $ticket = clientTicket(Client::factory()->create());
    $staff = supportStaff([$ticket->department]);
    app(PostReply::class)->handle($ticket, TicketAuthor::staff($staff), 'log', [IncomingFile::fromContents('a.txt', 'hello')], TicketStatus::Closed);
    $ticket->forceFill(['closed_at' => now()->subMonths(7)])->save();
    $attachment = TicketAttachment::query()->sole();

    expect(app(PruneAttachments::class)->handle())->toBe(0);

    app(Settings::class)->set('support.prune_attachments_after_months', 6);
    expect(app(PruneAttachments::class)->handle())->toBe(1)
        ->and(app(PruneAttachments::class)->handle())->toBe(0)
        ->and($attachment->fresh()->pruned_at)->not->toBeNull();
    Storage::disk('objects')->assertMissing($attachment->path);
});
