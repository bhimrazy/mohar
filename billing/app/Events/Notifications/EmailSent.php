<?php

namespace App\Events\Notifications;

use App\Support\Events\IdentifiesOccurrence;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Emitted when the transport accepted an email.
 */
final class EmailSent
{
    use Dispatchable, IdentifiesOccurrence;

    public function __construct(
        public int $emailMessageId,
        public ?string $templateKey,
        public ?int $clientId,
    ) {}
}
