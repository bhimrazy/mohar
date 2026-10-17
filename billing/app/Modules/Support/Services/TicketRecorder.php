<?php

namespace App\Modules\Support\Services;

use App\Modules\Support\Data\TicketAuthor;
use App\Modules\Support\Models\Ticket;
use App\Modules\Support\Models\TicketEvent;

/**
 * Writes the ticket audit trail (ticket_events). Each entry stores the ticket version after the
 * change so a stale form can be told exactly what changed (collision detection).
 */
class TicketRecorder
{
    /**
     * @param  array<string, mixed>|null  $meta
     */
    public function record(Ticket $ticket, TicketAuthor $actor, string $type, ?string $from = null, ?string $to = null, ?array $meta = null): TicketEvent
    {
        return TicketEvent::query()->create([
            'ticket_id' => $ticket->id,
            'user_id' => $actor->user?->id,
            'actor' => $actor->type->value,
            'type' => $type,
            'from' => $from,
            'to' => $to,
            'meta' => ['actor_name' => $actor->name, ...($meta ?? [])],
            'version' => $ticket->version,
        ]);
    }

    /**
     * Human summary of what changed after the given version.
     *
     * @return list<string>
     */
    public function changesSince(Ticket $ticket, int $version): array
    {
        return array_values(TicketEvent::query()
            ->where('ticket_id', $ticket->id)
            ->where('version', '>', $version)
            ->orderBy('id')
            ->get()
            ->map(fn (TicketEvent $event): string => $this->describe($event))
            ->all());
    }

    public function describe(TicketEvent $event): string
    {
        $who = $event->meta['actor_name'] ?? ucfirst($event->actor);

        return match ($event->type) {
            'opened' => "{$who} opened the ticket.",
            'reply' => "{$who} replied.",
            'note' => "{$who} added a note.",
            'status' => "{$who} changed the status from {$event->from} to {$event->to}.",
            'priority' => "{$who} changed the priority from {$event->from} to {$event->to}.",
            'department' => "{$who} moved the ticket from {$event->from} to {$event->to}.",
            'assigned' => "{$who} assigned the ticket to {$event->to}.",
            'unassigned' => "{$who} unassigned {$event->from}.",
            'subject' => "{$who} changed the subject.",
            'cc' => "{$who} changed the CC recipients.",
            'tags' => "{$who} changed the tags.",
            'closed' => "{$who} closed the ticket.",
            'reopened' => "{$who} reopened the ticket.",
            'merged' => "{$who} merged tickets {$event->from} into this ticket.",
            'merged_into' => "{$who} merged this ticket into {$event->to}.",
            'message_edited' => "{$who} edited a message.",
            'message_deleted' => "{$who} deleted a message.",
            'client_linked' => "{$who} linked the ticket to client {$event->to}.",
            'email_imported' => "{$who} imported a blocked email reply.",
            'pinned' => "{$who} ".($event->to === '1' ? 'pinned' : 'unpinned').' the ticket.',
            default => "{$who}: {$event->type}.",
        };
    }
}
