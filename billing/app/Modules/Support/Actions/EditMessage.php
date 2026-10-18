<?php

namespace App\Modules\Support\Actions;

use App\Models\User;
use App\Modules\Support\Data\TicketAuthor;
use App\Modules\Support\Models\Ticket;
use App\Modules\Support\Models\TicketMessage;
use App\Modules\Support\Services\MarkdownRenderer;
use App\Modules\Support\Services\TicketRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Staff edits/deletes of replies and notes; the previous body is kept in the audit trail.
 * Deleting a message also deletes its attachments (rows and files), so a removed internal note
 * can never leave orphaned files reachable through customer endpoints.
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

        $files = $message->attachments()->whereNull('pruned_at')->get(['disk', 'path']);

        DB::transaction(function () use ($message, $staff): void {
            /** @var Ticket $ticket */
            $ticket = $message->ticket;
            $this->recorder->record($ticket, TicketAuthor::staff($staff), 'message_deleted', null, null, [
                'message_id' => $message->id,
                'previous_body' => $message->body,
                'attachments' => $message->attachments()->pluck('original_name')->all(),
            ]);
            $message->attachments()->delete();
            $message->delete();
        });

        foreach ($files as $file) {
            Storage::disk($file->disk)->delete($file->path);
        }
    }
}
