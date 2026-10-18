<?php

namespace App\Modules\Notifications\Listeners;

use App\Modules\Notifications\Webhooks\WebhookEventTypes;
use App\Modules\Notifications\Webhooks\WebhookPublisher;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Events\Dispatcher;
use Throwable;

/**
 * Publishes the mapped domain events to subscribed webhook endpoints, after the transaction commits.
 */
class PublishEventWebhooks implements ShouldHandleEventsAfterCommit
{
    public function __construct(private WebhookPublisher $publisher) {}

    /**
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return array_fill_keys(array_keys(WebhookEventTypes::MAP), 'handle');
    }

    public function handle(object $event): void
    {
        try {
            $this->publisher->publishEvent($event);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
