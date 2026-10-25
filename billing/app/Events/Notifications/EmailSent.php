<?php

namespace App\Events\Notifications;

use App\Contracts\Events\DomainEvent;
use App\Support\Events\IdentifiesOccurrence;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Emitted when the transport accepted an email. $relatedType and $relatedId name the record the message is about (a
 * merge-field entity key and its id), when it has one.
 */
final class EmailSent implements DomainEvent
{
    use Dispatchable, IdentifiesOccurrence;

    public function __construct(
        public int $emailMessageId,
        public ?string $templateKey,
        public ?int $clientId,
        public ?string $relatedType = null,
        public ?int $relatedId = null,
    ) {}
}
