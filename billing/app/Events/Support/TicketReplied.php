<?php

namespace App\Events\Support;

use App\Support\Events\IdentifiesOccurrence;
use Illuminate\Foundation\Events\Dispatchable;

final class TicketReplied
{
    use Dispatchable, IdentifiesOccurrence;

    public function __construct(
        public int $ticketId,
        public int $replyId,
        public ?int $clientId,
        public bool $byStaff,
    ) {}
}
