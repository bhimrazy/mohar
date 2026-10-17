<?php

namespace App\Events\Support;

use Illuminate\Foundation\Events\Dispatchable;

final class TicketNoteAdded
{
    use Dispatchable;

    public function __construct(
        public int $ticketId,
        public int $replyId,
        public int $staffUserId,
    ) {}
}
