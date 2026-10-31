<?php

namespace App\Modules\Support\Queries;

use App\Models\User;
use App\Modules\Core\Support\RequestMemo;
use App\Modules\Support\Models\Ticket;
use App\Modules\Support\Services\TicketAccess;
use App\Modules\Support\Settings\SupportSettings;
use Illuminate\Database\Eloquent\Builder;

/**
 * Tickets awaiting a staff reply that the staff member may see (status index + department scope).
 */
final class AwaitingTickets
{
    /**
     * @return Builder<Ticket>
     */
    public static function query(User $user): Builder
    {
        return app(TicketAccess::class)->scope(Ticket::query()->notMerged(), $user)
            ->whereIn('status', app(SupportSettings::class)->awaitingReplyStatuses());
    }

    /** The nav badge and the to-do share one count per request. */
    public static function count(User $user): int
    {
        return RequestMemo::remember('support.awaiting.'.$user->id, fn (): int => self::query($user)->count());
    }
}
