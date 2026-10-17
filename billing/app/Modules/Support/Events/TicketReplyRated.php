<?php

namespace App\Modules\Support\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A client or guest rated a staff reply (1-5).
 *
 * Module-local until promoted to App\Events\Support by the shared-kernel owner (spec §12).
 */
final class TicketReplyRated
{
    use Dispatchable;

    public function __construct(
        public int $ticketId,
        public int $replyId,
        public int $rating,
    ) {}
}
