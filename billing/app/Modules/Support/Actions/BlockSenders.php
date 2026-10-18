<?php

namespace App\Modules\Support\Actions;

use App\Models\User;
use App\Modules\Support\Data\TicketAuthor;
use App\Modules\Support\Enums\ClosedBy;
use App\Modules\Support\Models\SpamRule;
use App\Modules\Support\Models\Ticket;
use Illuminate\Support\Collection;

/**
 * Bulk "block sender" (spec §9): adds an exact-match sender spam rule for each ticket's requester
 * and closes the tickets. Department addresses and already blocked senders are skipped.
 */
class BlockSenders
{
    public function __construct(private CloseTicket $close) {}

    /**
     * @param  Collection<int, Ticket>  $tickets
     * @return int number of senders newly blocked
     */
    public function handle(Collection $tickets, User $staff): int
    {
        $blocked = 0;

        foreach ($tickets->pluck('requester_email')->map(fn (string $email): string => mb_strtolower($email))->unique() as $email) {
            $rule = SpamRule::query()->firstOrCreate(['field' => 'sender', 'match' => 'equals', 'value' => $email]);
            $blocked += $rule->wasRecentlyCreated ? 1 : 0;
        }

        foreach ($tickets as $ticket) {
            $this->close->handle($ticket, ClosedBy::Staff, TicketAuthor::staff($staff));
        }

        return $blocked;
    }
}
