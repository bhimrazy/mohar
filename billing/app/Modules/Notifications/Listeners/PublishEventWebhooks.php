<?php

namespace App\Modules\Notifications\Listeners;

use App\Modules\Core\Support\AccountLinks;
use App\Modules\Notifications\Webhooks\WebhookPublisher;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Throwable;

/**
 * Publishes the mapped domain events to subscribed webhook endpoints, after the transaction commits.
 */
class PublishEventWebhooks implements ShouldHandleEventsAfterCommit
{
    public function __construct(private WebhookPublisher $publisher) {}

    public function handle(object $event): void
    {
        try {
            // Payload URLs come from merge-field resolvers: root them at APP_URL, never the request's Host.
            AccountLinks::withAppOrigin(fn () => $this->publisher->publishEvent($event));
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
