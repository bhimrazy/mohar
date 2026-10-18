<?php

namespace App\Events\Support;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A client or guest rated a staff reply (1-5).
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
