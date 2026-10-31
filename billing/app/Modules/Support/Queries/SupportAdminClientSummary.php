<?php

namespace App\Modules\Support\Queries;

use App\Models\User;
use App\Modules\Core\Models\Client;
use App\Modules\Support\Enums\TicketStatus;
use App\Modules\Support\Models\Ticket;
use App\Modules\Support\Services\TicketAccess;
use App\Support\Dashboard\Dashboard;
use Illuminate\Database\Eloquent\Builder;

/**
 * Support's part of the admin client page ("admin-client" dashboard area): open tickets within what the staff member
 * may see, and the latest of them.
 */
class SupportAdminClientSummary
{
    private const RECENT = 5;

    public function register(Dashboard $dashboard): void
    {
        $dashboard->card('admin-client', 'support.count', fn (?User $user, Client $client): array => [
            'label' => 'Open tickets',
            'value' => $this->open($user, $client)->count(),
            'href' => route('admin.support.tickets.index', ['client' => $client->id]),
            'icon' => 'LifeBuoy',
        ], 'support.tickets.view', 23);

        $dashboard->widget('admin-client', 'support.recent', 'list', fn (?User $user, Client $client): array => [
            'title' => 'Open tickets',
            'items' => $this->open($user, $client)->orderByDesc('last_reply_at')->orderByDesc('id')->limit(self::RECENT)
                ->get(['id', 'number', 'subject', 'status', 'last_reply_at'])
                ->map(fn (Ticket $ticket): array => [
                    'title' => $ticket->subject,
                    'subtitle' => '#'.$ticket->number,
                    'href' => route('admin.support.tickets.show', $ticket),
                    'meta' => $ticket->last_reply_at?->toIso8601String(),
                    'status' => $ticket->status->value,
                ])->values()->all(),
            'href' => route('admin.support.tickets.index', ['client' => $client->id]),
            'empty' => 'No open tickets.',
        ], 'support.tickets.view', 33);
    }

    /**
     * @return Builder<Ticket>
     */
    private function open(?User $user, Client $client): Builder
    {
        $tickets = Ticket::query()->notMerged()->where('client_id', $client->id)->where('status', '!=', TicketStatus::Closed->value);

        return $user === null ? $tickets->whereRaw('1 = 0') : app(TicketAccess::class)->scope($tickets, $user);
    }
}
