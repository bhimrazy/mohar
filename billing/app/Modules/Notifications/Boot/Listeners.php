<?php

namespace App\Modules\Notifications\Boot;

use App\Modules\Notifications\Listeners\BrandOutgoingMail;
use App\Modules\Notifications\Listeners\PublishEventWebhooks;
use App\Modules\Notifications\Listeners\SendEventEmails;
use App\Support\Events\WebhookEvents;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Event;

final class Listeners
{
    public function __invoke(Application $app): void
    {
        // Framework and module notifications (password reset fallback, security notices, invitations) get the
        // company sender name and the support Reply-To too; templated mail sets its own per category.
        Event::listen(MessageSending::class, [BrandOutgoingMail::class, 'handle']);

        foreach (array_keys(SendEventEmails::EVENTS) as $event) {
            Event::listen($event, [SendEventEmails::class, 'handle']);
        }

        // After every provider has booted, so events registered by sibling modules and packages are included.
        $app->booted(function () use ($app): void {
            foreach ($app->make(WebhookEvents::class)->events() as $event) {
                Event::listen($event, [PublishEventWebhooks::class, 'handle']);
            }
        });
    }
}
