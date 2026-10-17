<?php

namespace App\Modules\Support\Actions;

use App\Events\Support\TicketNoteAdded;
use App\Models\User;
use App\Modules\Support\Data\TicketAuthor;
use App\Modules\Support\Enums\MessageType;
use App\Modules\Support\Enums\TicketSource;
use App\Modules\Support\Models\Ticket;
use App\Modules\Support\Models\TicketMessage;
use App\Modules\Support\Services\IncomingFile;
use App\Modules\Support\Services\MessageWriter;
use App\Modules\Support\Services\TicketRecorder;
use Illuminate\Support\Facades\DB;

/**
 * Internal staff note: never shown to clients, never changes status (spec §2).
 */
class AddNote
{
    public function __construct(private MessageWriter $messages, private TicketRecorder $recorder) {}

    /**
     * @param  list<IncomingFile>  $files
     */
    public function handle(Ticket $ticket, User $staff, string $body, array $files = []): TicketMessage
    {
        $author = TicketAuthor::staff($staff);

        $note = DB::transaction(function () use ($ticket, $author, $body, $files): TicketMessage {
            $note = $this->messages->write($ticket, $author, MessageType::Note, $body, TicketSource::Staff, $files);
            $ticket->version = $ticket->version + 1;
            $ticket->save();
            $this->recorder->record($ticket, $author, 'note', null, null, ['message_id' => $note->id]);

            return $note;
        });

        TicketNoteAdded::dispatch($ticket->id, $note->id, $staff->id);

        return $note;
    }
}
