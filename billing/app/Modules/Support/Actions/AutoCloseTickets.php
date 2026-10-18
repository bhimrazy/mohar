<?php

namespace App\Modules\Support\Actions;

use App\Events\Support\TicketClosed;
use App\Modules\Support\Data\TicketAuthor;
use App\Modules\Support\Enums\ClosedBy;
use App\Modules\Support\Models\Ticket;
use App\Modules\Support\Services\SupportSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Closes idle tickets in an auto-close status (spec §4.6). Batches of 500, each ticket re-checked
 * under a row lock, so overlapping or repeated runs never double-close.
 */
class AutoCloseTickets
{
    public const BATCH = 500;

    public function __construct(private SupportSettings $settings, private CloseTicket $closer) {}

    public function handle(?CarbonImmutable $now = null): int
    {
        $hours = $this->settings->int('auto_close_hours', 72);
        $statuses = $this->settings->autoCloseStatuses();

        if ($hours <= 0 || $statuses === []) {
            return 0;
        }

        $cutoff = ($now ?? CarbonImmutable::now())->subHours($hours);
        $closed = 0;

        Ticket::query()
            ->whereIn('status', $statuses)
            ->whereNull('merged_into_id')
            ->where('last_reply_at', '<=', $cutoff)
            ->select('id')
            ->chunkById(self::BATCH, function ($tickets) use ($statuses, $cutoff, &$closed): void {
                foreach ($tickets as $candidate) {
                    $ticket = DB::transaction(function () use ($candidate, $statuses, $cutoff): ?Ticket {
                        $ticket = Ticket::query()->lockForUpdate()->find($candidate->id);

                        if ($ticket === null || ! in_array($ticket->status->value, $statuses, true)
                            || $ticket->last_reply_at === null || $ticket->last_reply_at->gt($cutoff)) {
                            return null;
                        }

                        $this->closer->apply($ticket, ClosedBy::System, TicketAuthor::system());

                        return $ticket;
                    });

                    if ($ticket !== null) {
                        $closed++;
                        TicketClosed::dispatch($ticket->id, $ticket->client_id, ClosedBy::System->value);
                    }
                }
            });

        return $closed;
    }
}
