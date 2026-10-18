<?php

namespace App\Modules\Support\Services;

use App\Modules\Support\Models\Ticket;

/**
 * Resolves {{ requester.name }} style tokens in staff replies and canned replies (spec §4.2).
 */
class MergeTokens
{
    public const TOKENS = ['requester.name', 'requester.first_name', 'requester.email', 'ticket.number', 'ticket.subject', 'client.company_name'];

    public function resolve(string $text, Ticket $ticket): string
    {
        $client = $ticket->client_id !== null ? $ticket->client()->first() : null;
        $values = [
            'requester.name' => $ticket->requester_name,
            'requester.first_name' => strtok($ticket->requester_name, ' ') ?: $ticket->requester_name,
            'requester.email' => $ticket->requester_email,
            'ticket.number' => $ticket->number,
            'ticket.subject' => $ticket->subject,
            'client.company_name' => (string) $client?->company_name,
        ];

        return (string) preg_replace_callback(
            '/\{\{\s*([a-z_]+\.[a-z_]+)\s*\}\}/',
            fn (array $match): string => $values[$match[1]] ?? $match[0],
            $text,
        );
    }
}
