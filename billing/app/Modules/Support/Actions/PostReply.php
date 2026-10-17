<?php

namespace App\Modules\Support\Actions;

use App\Events\Support\TicketClosed;
use App\Events\Support\TicketReplied;
use App\Modules\Support\Data\TicketAuthor;
use App\Modules\Support\Enums\ClosedBy;
use App\Modules\Support\Enums\MessageType;
use App\Modules\Support\Enums\TicketSource;
use App\Modules\Support\Enums\TicketStatus;
use App\Modules\Support\Events\TicketReopened;
use App\Modules\Support\Exceptions\TicketVersionConflict;
use App\Modules\Support\Models\Ticket;
use App\Modules\Support\Models\TicketMessage;
use App\Modules\Support\Services\IncomingFile;
use App\Modules\Support\Services\MessageWriter;
use App\Modules\Support\Services\TicketRecorder;
use Illuminate\Support\Facades\DB;

/**
 * Adds a public reply. Staff replies default to "answered" (or the chosen status); client, guest and
 * email replies set "customer_reply" and reopen closed tickets (spec §3).
 */
class PostReply
{
    public function __construct(
        private MessageWriter $messages,
        private TicketRecorder $recorder,
        private CloseTicket $closer,
    ) {}

    /**
     * @param  list<IncomingFile>  $files
     *
     * @throws TicketVersionConflict
     */
    public function handle(
        Ticket $ticket,
        TicketAuthor $author,
        string $body,
        array $files = [],
        ?TicketStatus $status = null,
        ?int $expectedVersion = null,
        bool $force = false,
        TicketSource $source = TicketSource::Web,
        ?string $emailMessageId = null,
        bool $unverified = false,
    ): TicketMessage {
        $reopened = false;
        $closedNow = false;

        $message = DB::transaction(function () use ($ticket, $author, $body, $files, $status, $expectedVersion, $force, $source, $emailMessageId, $unverified, &$reopened, &$closedNow): TicketMessage {
            $locked = Ticket::query()->lockForUpdate()->findOrFail($ticket->id);

            if ($expectedVersion !== null && ! $force && $locked->version !== $expectedVersion) {
                throw new TicketVersionConflict($locked->version, $this->recorder->changesSince($locked, $expectedVersion));
            }

            $previous = $locked->status;
            $next = $author->isStaff() ? ($status ?? TicketStatus::Answered) : TicketStatus::CustomerReply;

            $message = $this->messages->write($locked, $author, MessageType::Reply, $body, $source, $files, $emailMessageId, unverified: $unverified);
            $this->messages->touchReplyTimestamps($locked, $author);

            if ($next === TicketStatus::Closed) {
                $locked->save();
                $this->recorder->record($locked, $author, 'reply', null, null, ['message_id' => $message->id]);
                $this->closer->apply($locked, ClosedBy::Staff, $author);
                $closedNow = $previous !== TicketStatus::Closed;
            } else {
                $locked->status = $next;

                if ($previous === TicketStatus::Closed) {
                    $locked->closed_at = null;
                    $locked->closed_by = null;
                    $reopened = true;
                }

                $locked->save();
                $this->recorder->record($locked, $author, 'reply', null, null, ['message_id' => $message->id]);

                if ($reopened) {
                    $this->recorder->record($locked, $author, 'reopened', $previous->value, $next->value);
                } elseif ($previous !== $next) {
                    $this->recorder->record($locked, $author, 'status', $previous->value, $next->value);
                }
            }

            $ticket->setRawAttributes($locked->getAttributes(), true);

            return $message;
        });

        TicketReplied::dispatch($ticket->id, $message->id, $ticket->client_id, $author->isStaff());

        if ($reopened) {
            TicketReopened::dispatch($ticket->id, $ticket->client_id, $author->type->value);
        }

        if ($closedNow) {
            TicketClosed::dispatch($ticket->id, $ticket->client_id, 'staff');
        }

        return $message;
    }
}
