<?php

namespace App\Events\Support;

use App\Support\Events\IdentifiesOccurrence;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A client or guest rated a staff reply (1-5).
 */
final class TicketReplyRated
{
    use Dispatchable, IdentifiesOccurrence;

    public function __construct(
        public int $ticketId,
        public int $replyId,
        public int $rating,
    ) {}
}
