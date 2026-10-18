<?php

namespace App\Events\Support;

use Illuminate\Foundation\Events\Dispatchable;

final class TicketNoteAdded
{
    use Dispatchable;

    /**
     * @param  list<int>  $mentions  staff user ids @mentioned in the note (only staff who can access the department)
     */
    public function __construct(
        public int $ticketId,
        public int $replyId,
        public int $staffUserId,
        public array $mentions = [],
    ) {}
}
