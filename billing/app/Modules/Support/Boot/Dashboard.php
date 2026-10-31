<?php

namespace App\Modules\Support\Boot;

use App\Models\User;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Support\ClientContext;
use App\Modules\Support\Enums\IncidentStatus;
use App\Modules\Support\Enums\TicketStatus;
use App\Modules\Support\Models\NetworkIncident;
use App\Modules\Support\Models\Ticket;
use App\Modules\Support\Queries\AwaitingTickets;
use App\Modules\Support\Queries\SupportAdminClientSummary;
use App\Modules\Support\Services\TicketAccess;
use App\Support\Dashboard\Dashboard as DashboardRegistry;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Route;

final class Dashboard
{
    public function __invoke(Container $app): void
    {
        $dashboard = $app->make(DashboardRegistry::class);
        $app->make(SupportAdminClientSummary::class)->register($dashboard);
        $scoped = fn (?User $user) => $user !== null
            ? app(TicketAccess::class)->scope(Ticket::query()->notMerged(), $user)
            : Ticket::query()->whereRaw('1 = 0');

        $dashboard->card('admin', 'support.open', fn (?User $user): array => [
            'label' => 'Open tickets',
            'value' => $scoped($user)->where('status', '!=', TicketStatus::Closed->value)->count(),
            'href' => route('admin.support.tickets.index'),
            'icon' => 'LifeBuoy',
        ], 'support.tickets.view', 50);

        $dashboard->card('admin', 'support.awaiting', fn (?User $user): array => [
            'label' => 'Awaiting staff reply',
            'value' => $user !== null ? AwaitingTickets::count($user) : 0,
            'href' => route('admin.support.tickets.index', ['view' => 'awaiting']),
            'icon' => 'Inbox',
        ], 'support.tickets.view', 51, mirrorsTodo: true);

        $dashboard->card('admin', 'support.mine', fn (?User $user): array => [
            'label' => 'My assigned tickets',
            'value' => $scoped($user)->where('assigned_to', $user?->id)->where('status', '!=', TicketStatus::Closed->value)->count(),
            'href' => route('admin.support.tickets.index', ['view' => 'mine']),
            'icon' => 'UserRound',
        ], 'support.tickets.view', 52);

        $dashboard->widget('admin', 'support.awaiting_list', 'list', fn (?User $user): array => [
            'title' => 'Tickets awaiting reply',
            'href' => route('admin.support.tickets.index', ['view' => 'awaiting']),
            'empty' => 'No tickets are waiting for a reply.',
            'items' => $user === null ? [] : array_values(AwaitingTickets::query($user)->with('department:id,name')
                ->orderByDesc('last_reply_at')->orderByDesc('id')->limit(5)->get()
                ->map(fn (Ticket $ticket): array => [
                    'title' => '#'.$ticket->number.' '.$ticket->subject,
                    'subtitle' => $ticket->requester_name.' · '.$ticket->department->name,
                    'avatar' => $ticket->requester_name,
                    'href' => route('admin.support.tickets.show', $ticket),
                    'meta' => $ticket->last_reply_at?->diffForHumans(),
                    'status' => $ticket->status->value,
                ])->all()),
        ], 'support.tickets.view', 53, 'md');

        $dashboard->todo('admin', 'support.awaiting', fn (?User $user): array => [
            'label' => 'Tickets awaiting staff reply',
            'count' => $user !== null ? AwaitingTickets::count($user) : 0,
            'href' => route('admin.support.tickets.index', ['view' => 'awaiting']),
            'tone' => 'warning',
            'icon' => 'LifeBuoy',
        ], 'support.tickets.view', 20);

        $dashboard->todo('admin', 'support.unassigned', fn (?User $user): array => [
            'label' => 'Unassigned open tickets',
            'count' => $scoped($user)->whereNull('assigned_to')->where('status', '!=', TicketStatus::Closed->value)->count(),
            'href' => route('admin.support.tickets.index', ['view' => 'unassigned']),
            'tone' => 'info',
            'icon' => 'UserRound',
        ], 'support.tickets.view', 21);

        $dashboard->todo('admin', 'support.incidents', fn (?User $user): array => [
            'label' => 'Active network incidents',
            'count' => NetworkIncident::query()->whereNotIn('status', [IncidentStatus::Resolved->value, IncidentStatus::Scheduled->value])->count(),
            'href' => route('admin.support.network.index'),
            'tone' => 'danger',
            'icon' => 'Activity',
        ], 'support.network.manage', 22);

        $dashboard->card('client', 'support.open', fn (?User $user, Client $client): ?array => app(ClientContext::class)->allows($user, $client, 'support') ? [
            'label' => 'Open tickets',
            'value' => Ticket::query()->notMerged()->where('client_id', $client->id)->where('status', '!=', TicketStatus::Closed->value)->count(),
            'href' => route('client.support.tickets.index'),
            'icon' => 'LifeBuoy',
        ] : null, order: 60);

        $dashboard->widget('client', 'support.tickets', 'list', function (?User $user, Client $client): ?array {
            if (! app(ClientContext::class)->allows($user, $client, 'support')) {
                return null;
            }

            // Tickets staff answered (awaiting the client) first, then the latest activity.
            $items = Ticket::query()->notMerged()->where('client_id', $client->id)->where('status', '!=', TicketStatus::Closed->value)
                ->orderByRaw('case when status = ? then 0 else 1 end', [TicketStatus::Answered->value])
                ->orderByDesc('last_reply_at')->orderByDesc('id')
                ->limit(3)
                ->get(['id', 'number', 'subject', 'status', 'last_reply_at'])
                ->map(fn (Ticket $ticket): array => [
                    'title' => $ticket->subject,
                    'subtitle' => '#'.$ticket->number,
                    'href' => route('client.support.tickets.show', $ticket->number),
                    // The client's view of who is waiting: staff answered → them; they replied → us.
                    'status' => match ($ticket->status) {
                        TicketStatus::Answered => 'awaiting-you',
                        TicketStatus::CustomerReply => 'waiting-for-us',
                        default => $ticket->status->value,
                    },
                    'meta' => $ticket->last_reply_at?->toIso8601String(),
                ])->values()->all();

            return [
                'title' => 'Support',
                'items' => $items,
                'href' => route('client.support.tickets.index'),
                'empty' => 'No open tickets. We are here if you need help.',
                'emptyAction' => ['label' => 'Open a ticket', 'href' => route('client.support.tickets.create')],
                // The empty state already offers "Open a ticket"; the header links are for clients with tickets.
                'actions' => $items === [] ? [] : [
                    ['label' => 'New ticket', 'href' => route('client.support.tickets.create'), 'icon' => 'Plus'],
                    ...(Route::has('support.kb.index') ? [['label' => 'Help articles', 'href' => route('support.kb.index'), 'icon' => 'BookOpen']] : []),
                ],
            ];
        }, order: 30);
    }
}
