<?php

namespace App\Modules\Notifications\Data;

/**
 * What to send: a template to a set of recipients for one domain event (eventKey makes replays idempotent).
 */
final readonly class EmailRequest
{
    /**
     * @param  list<Recipient>  $recipients
     * @param  array<string, mixed>  $context  entity data shared by all recipients
     * @param  bool  $carriesSecret  the event hands over a one-time secret: never copy the message to anyone
     * @param  bool  $resumeQueued  a retried composer: a message already logged and still queued gets its send queued
     *                              again (its first one may never have been dispatched; SendEmailMessage is unique per message)
     */
    public function __construct(
        public string $templateKey,
        public array $recipients,
        public array $context,
        public string $eventKey,
        public ?int $clientId = null,
        public ?string $relatedType = null,
        public ?int $relatedId = null,
        public ?string $fromEmail = null,
        public bool $carriesSecret = false,
        public bool $resumeQueued = false,
    ) {}
}
