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
use App\Modules\Support\Services\TicketAccess;
use App\Modules\Support\Services\TicketRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Internal staff note: never shown to clients, never changes status (spec §2).
 */
class AddNote
{
    public function __construct(
        private MessageWriter $messages,
        private TicketRecorder $recorder,
        private TicketAccess $access,
    ) {}

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

        TicketNoteAdded::dispatch($ticket->id, $note->id, $staff->id, $this->mentions($ticket, $staff, $body));

        return $note;
    }

    /**
     * Staff mentioned as "@handle", where the handle is the local part of their email address
     * (e.g. @sam for sam@example.com). Only staff who can access the department count (spec §4.2).
     *
     * @return list<int>
     */
    private function mentions(Ticket $ticket, User $author, string $body): array
    {
        preg_match_all('/(?<![\w@])@([A-Za-z0-9._-]{2,64})/', $body, $matches);
        $handles = array_values(array_unique(array_map('mb_strtolower', $matches[1])));

        if ($handles === []) {
            return [];
        }

        return array_values(User::query()->where('is_staff', true)->whereKeyNot($author->id)->with('role')->orderBy('id')->get()
            ->filter(fn (User $user): bool => in_array(mb_strtolower(Str::before($user->email, '@')), $handles, true)
                && $this->access->canAccessDepartment($user, $ticket->department_id))
            ->map(fn (User $user): int => $user->id)
            ->all());
    }
}
