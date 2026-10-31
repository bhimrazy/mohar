<?php

namespace App\Modules\Support\Boot;

use App\Events\Support\TicketClosed;
use App\Events\Support\TicketOpened;
use App\Events\Support\TicketReplied;
use App\Support\Events\WebhookEvents;
use Illuminate\Contracts\Container\Container;

final class Webhooks
{
    public function __invoke(Container $app): void
    {
        $webhooks = $app->make(WebhookEvents::class);
        $webhooks->register(TicketOpened::class, 'ticket.opened', 'ticket', 'ticketId');
        $webhooks->register(TicketReplied::class, 'ticket.replied', 'ticket', 'ticketId');
        $webhooks->register(TicketClosed::class, 'ticket.closed', 'ticket', 'ticketId');
    }
}
