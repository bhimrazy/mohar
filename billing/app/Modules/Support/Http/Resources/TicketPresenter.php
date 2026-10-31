<?php

namespace App\Modules\Support\Http\Resources;

use App\Modules\Support\Models\Ticket;
use App\Modules\Support\Models\TicketAttachment;
use App\Modules\Support\Models\TicketMessage;
use App\Modules\Support\Settings\SupportSettings;

/**
 * Shapes ticket props for Inertia per audience. Clients and guests never receive internal notes,
 * internal ids, staff emails or the raw access key of other tickets.
 */
class TicketPresenter
{
    public const ADMIN = 'admin';

    public const CLIENT = 'client';

    public const GUEST = 'guest';

    public function __construct(private SupportSettings $settings) {}

    /**
     * @return array<string, mixed>
     */
    public function listItem(Ticket $ticket, string $audience = self::ADMIN): array
    {
        $item = [
            'number' => $ticket->number,
            'subject' => $ticket->subject,
            'status' => $ticket->status->value,
            'priority' => $ticket->priority->value,
            'department' => $ticket->relationLoaded('department') ? $ticket->department->name : null,
            'last_reply_at' => $ticket->last_reply_at?->toIso8601String(),
            'created_at' => $ticket->created_at?->toIso8601String(),
        ];

        if ($audience !== self::ADMIN) {
            return $item;
        }

        return [
            'id' => $ticket->id,
            ...$item,
            'requester_name' => $ticket->requester_name,
            'requester_email' => $ticket->requester_email,
            'client_id' => $ticket->client_id,
            'client' => $ticket->relationLoaded('client') ? $ticket->client?->display_name : null,
            'assignee' => $ticket->relationLoaded('assignee') ? $ticket->assignee?->name : null,
            'is_pinned' => $ticket->is_pinned,
            'is_unverified_requester' => $ticket->is_unverified_requester,
            'tags' => $ticket->tags ?? [],
            'source' => $ticket->source->value,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(Ticket $ticket, string $audience = self::ADMIN): array
    {
        $detail = [
            ...$this->listItem($ticket, $audience),
            'department' => $ticket->department->name,
            'requester_name' => $ticket->requester_name,
            'service_id' => $ticket->service_id,
            'closed_at' => $ticket->closed_at?->toIso8601String(),
            'can_close' => $ticket->status->value !== 'closed' && ! $ticket->prevent_client_close,
        ];

        if ($audience !== self::ADMIN) {
            return $detail;
        }

        return [
            ...$detail,
            'department_id' => $ticket->department_id,
            'assigned_to' => $ticket->assigned_to,
            'cc' => $ticket->cc ?? [],
            'prevent_client_close' => $ticket->prevent_client_close,
            'version' => $ticket->version,
            'first_staff_reply_at' => $ticket->first_staff_reply_at?->toIso8601String(),
            'closed_by' => $ticket->closed_by?->value,
            'is_guest' => $ticket->isGuest(),
            'is_unverified_requester' => $ticket->is_unverified_requester,
            'guest_url' => route('support.tickets.show', ['number' => $ticket->number, 'key' => $ticket->access_key]),
        ];
    }

    /**
     * @param  iterable<TicketMessage>  $messages
     * @return list<array<string, mixed>>
     */
    public function messages(iterable $messages, Ticket $ticket, string $audience): array
    {
        $ratings = $audience !== self::ADMIN && $this->settings->bool('reply_ratings_enabled', true);
        $items = [];

        foreach ($messages as $message) {
            if ($audience !== self::ADMIN && $message->isNote()) {
                continue;
            }

            $item = [
                'id' => $message->id,
                'type' => $message->type->value,
                'author_type' => $message->author_type->value,
                'author_name' => $message->author_name,
                'body_html' => $message->body_html,
                'created_at' => $message->created_at?->toIso8601String(),
                'edited_at' => $message->edited_at?->toIso8601String(),
                'is_autoresponse' => $message->is_autoresponse,
                'rating' => $message->rating,
                'can_rate' => $ratings && $message->isStaffReply(),
                'attachments' => $message->attachments->map(fn (TicketAttachment $attachment): array => $this->attachment($attachment, $ticket, $audience))->values()->all(),
            ];

            if ($audience === self::ADMIN) {
                $item['author_email'] = $message->author_email;
                $item['body'] = $message->body;
                $item['source'] = $message->source->value;
                $item['is_unverified_sender'] = $message->is_unverified_sender;
            }

            $items[] = $item;
        }

        return $items;
    }

    /**
     * @return array<string, mixed>
     */
    public function attachment(TicketAttachment $attachment, Ticket $ticket, string $audience): array
    {
        $url = match ($audience) {
            self::ADMIN => route('admin.support.attachments.show', $attachment),
            self::CLIENT => route('client.support.tickets.attachments.show', ['number' => $ticket->number, 'attachment' => $attachment->id]),
            default => route('support.tickets.attachments.show', ['number' => $ticket->number, 'attachment' => $attachment->id, 'key' => $ticket->access_key]),
        };

        return [
            'id' => $attachment->id,
            'name' => $attachment->original_name,
            'size_bytes' => $attachment->size_bytes,
            'mime' => $attachment->mime,
            'is_image' => $attachment->isImage(),
            'pruned' => $attachment->pruned_at !== null,
            'url' => $url,
        ];
    }

    /**
     * Upload limits for the reply/open forms.
     *
     * @return array{extensions: list<string>, max_mb: int, max_files: int}
     */
    public function uploadLimits(bool $staff): array
    {
        return [
            'extensions' => $this->settings->allowedExtensions($staff),
            'max_mb' => $this->settings->int('attachment_max_mb', 10),
            'max_files' => $this->settings->int('attachment_max_files', 5),
        ];
    }
}
