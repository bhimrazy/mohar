<?php

namespace App\Modules\Support\Boot;

use App\Contracts\Notifications\MergeFieldRegistry;
use App\Data\Notifications\SensitiveValue;
use App\Modules\Support\Models\Ticket;
use App\Modules\Support\Models\TicketMessage;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Str;

final class MergeFields
{
    public function __invoke(Container $app): void
    {
        $registry = $app->make(MergeFieldRegistry::class);

        $registry->register('ticket', function (int $id): array {
            $ticket = Ticket::query()->with('department')->findOrFail($id);

            return [
                'ticket_id' => $ticket->id,
                'client_id' => $ticket->client_id,
                'number' => $ticket->number,
                'subject' => $ticket->subject,
                'status' => $ticket->status->label(),
                'priority' => $ticket->priority->label(),
                'department' => $ticket->department->name,
                'department_email' => $ticket->department->email,
                'requester_name' => $ticket->requester_name,
                'requester_email' => $ticket->requester_email,
                'cc' => $ticket->cc ?? [],
                'is_guest' => $ticket->isGuest(),
                // A guest link carries the ticket's access key: sensitive, so only the system's ticket button carries it.
                'url' => $ticket->isGuest()
                    ? new SensitiveValue(route('support.tickets.show', ['number' => $ticket->number, 'key' => $ticket->access_key]), secret: $ticket->access_key)
                    : route('client.support.tickets.show', $ticket->number),
                'admin_url' => route('admin.support.tickets.show', $ticket),
                'email_subject' => '[#'.$ticket->number.'] '.$ticket->subject,
                'autoresponse' => $ticket->department->send_autoresponse,
            ];
        });

        $registry->register('ticket_reply', function (int $id): array {
            $message = TicketMessage::query()->with(['ticket', 'attachments'])->findOrFail($id);

            return [
                'reply_id' => $message->id,
                'ticket_id' => $message->ticket_id,
                'client_id' => $message->ticket->client_id,
                'author_name' => $message->author_name,
                'author_type' => $message->author_type->value,
                'body' => $message->body,
                'body_html' => $message->body_html,
                'excerpt' => Str::limit(strip_tags($message->body_html), 200),
                'message_id' => $message->email_message_id,
                'is_note' => $message->isNote(),
                'is_autoresponse' => $message->is_autoresponse,
                'attachments' => $message->attachments->map(fn ($attachment): array => [
                    'id' => $attachment->id, 'name' => $attachment->original_name, 'size_bytes' => $attachment->size_bytes,
                ])->all(),
            ];
        });
    }
}
