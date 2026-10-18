<?php

namespace App\Modules\Support\Actions;

use App\Events\Support\TicketClosed;
use App\Modules\Support\Data\TicketAuthor;
use App\Modules\Support\Enums\ClosedBy;
use App\Modules\Support\Enums\TicketStatus;
use App\Modules\Support\Models\Ticket;
use App\Modules\Support\Services\TicketRecorder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class CloseTicket
{
    public function __construct(private TicketRecorder $recorder) {}

    /**
     * @throws AuthorizationException when a client tries to close a ticket with prevent_client_close
     */
    public function handle(Ticket $ticket, ClosedBy $closedBy, TicketAuthor $actor): Ticket
    {
        if ($closedBy === ClosedBy::Client && $ticket->prevent_client_close) {
            throw new AuthorizationException(__('This ticket can only be closed by our team.'));
        }

        $closed = DB::transaction(function () use ($ticket, $closedBy, $actor): bool {
            $locked = Ticket::query()->lockForUpdate()->findOrFail($ticket->id);

            if ($locked->status === TicketStatus::Closed) {
                return false;
            }

            $this->apply($locked, $closedBy, $actor);
            $ticket->setRawAttributes($locked->getAttributes(), true);

            return true;
        });

        if ($closed) {
            TicketClosed::dispatch($ticket->id, $ticket->client_id, $closedBy->value);
        }

        return $ticket;
    }

    /**
     * Mark closed without dispatching (callers inside a transaction dispatch afterwards).
     */
    public function apply(Ticket $ticket, ClosedBy $closedBy, TicketAuthor $actor): void
    {
        $from = $ticket->status->value;
        $ticket->forceFill([
            'status' => TicketStatus::Closed,
            'closed_at' => now(),
            'closed_by' => $closedBy,
        ])->save();

        $this->recorder->record($ticket, $actor, 'closed', $from, TicketStatus::Closed->value, ['closed_by' => $closedBy->value]);
    }
}
