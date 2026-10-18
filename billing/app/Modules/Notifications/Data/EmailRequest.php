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
    ) {}
}
