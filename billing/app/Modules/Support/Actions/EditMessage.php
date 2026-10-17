<?php

namespace App\Modules\Support\Actions;

use App\Models\User;
use App\Modules\Support\Data\TicketAuthor;
use App\Modules\Support\Models\Ticket;
use App\Modules\Support\Models\TicketMessage;
use App\Modules\Support\Services\MarkdownRenderer;
use App\Modules\Support\Services\TicketRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Staff edits/deletes of replies and notes; the previous body is kept in the audit trail.
 */
class EditMessage
{
    public function __construct(private MarkdownRenderer $markdown, private TicketRecorder $recorder) {}

    public function update(TicketMessage $message, User $staff, string $body): TicketMessage
    {
        return DB::transaction(function () use ($message, $staff, $body): TicketMessage {
            $previous = $message->body;
            $message->forceFill([
                'body' => $body,
                'body_html' => $this->markdown->render($body),
                'edited_at' => now(),
                'edited_by' => $staff->id,
            ])->save();

            $this->recorder->record($message->ticket, TicketAuthor::staff($staff), 'message_edited', null, null, [
                'message_id' => $message->id,
                'previous_body' => $previous,
            ]);

            return $message;
        });
    }

    public function delete(TicketMessage $message, User $staff): void
    {
        $first = TicketMessage::query()->where('ticket_id', $message->ticket_id)->orderBy('created_at')->orderBy('id')->value('id');

        if ($first === $message->id) {
            throw ValidationException::withMessages(['message' => __('The first message cannot be deleted. Delete the ticket instead.')]);
        }

        DB::transaction(function () use ($message, $staff): void {
            /** @var Ticket $ticket */
            $ticket = $message->ticket;
            $this->recorder->record($ticket, TicketAuthor::staff($staff), 'message_deleted', null, null, [
                'message_id' => $message->id,
                'previous_body' => $message->body,
            ]);
            $message->attachments()->update(['message_id' => null]);
            $message->delete();
        });
    }
}
